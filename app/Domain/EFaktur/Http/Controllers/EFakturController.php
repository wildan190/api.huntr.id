<?php

namespace App\Domain\EFaktur\Http\Controllers;

use App\Domain\EFaktur\Actions\CreateEFakturAction;
use App\Domain\EFaktur\Http\Requests\StoreEFakturRequest;
use App\Domain\EFaktur\Http\Requests\UploadEFakturRequest;
use App\Domain\EFaktur\Http\Requests\VatInPrepopulatedRequest;
use App\Domain\EFaktur\Http\Requests\VatInUploadRequest;
use App\Domain\EFaktur\Http\Requests\VatInVerifyRequest;
use App\Domain\EFaktur\Models\EFaktur;
use App\Domain\EFaktur\Services\PajakExpressService;
use App\Domain\Order\Models\Bast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EFakturController extends \App\Http\Controllers\Controller
{
    public function __construct(
        private readonly PajakExpressService $pajakExpress
    ) {}

    /* ═══════════════════════════════════════════════════════════════ */
    /*  VAT OUT — Faktur Pajak Keluaran                                */
    /* ═══════════════════════════════════════════════════════════════ */

    /**
     * Reference data: goods codes + satuan codes dari PajakExpress.
     * GET /api/efaktur/references
     */
    public function references(): JsonResponse
    {
        try {
            [$goods, $satuan] = [
                $this->pajakExpress->getReference('goods'),
                $this->pajakExpress->getSatuanReference(),
            ];

            return response()->json([
                'goods'  => $goods,
                'satuan' => $satuan,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ambil item-item dari BAST (via PO) untuk preview sebelum terbitkan faktur.
     * GET /api/efaktur/bast/{bastId}/items
     */
    public function bastItems(string $bastId): JsonResponse
    {
        $bast = Bast::with(['purchaseOrder.historicalItems', 'purchaseOrder.rfq.items.catalogue', 'purchaseOrder.rfq.proposals.items'])->findOrFail($bastId);
        $po   = $bast->purchaseOrder;

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 422);
        }

        $items = [];

        if ($po->is_historical && $po->historicalItems?->isNotEmpty()) {
            $items = $po->historicalItems->map(fn($i) => [
                'id'         => $i->id,
                'nama'       => $i->inventory_name ?? 'Barang',
                'qty'        => (float) ($i->qty ?? 1),
                'unit_price' => (float) ($i->unit_price ?? 0),
                'uom'        => $i->uom ?? 'Pc',
                'total'      => round((float)($i->qty ?? 1) * (float)($i->unit_price ?? 0)),
            ])->values()->toArray();
        } elseif ($po->rfq?->items?->isNotEmpty()) {
            $proposal = $po->rfq->proposals->where('status', 'accepted')->first();
            $items = $po->rfq->items->map(function ($i) use ($proposal) {
                $pItem     = $proposal?->items->where('rfq_item_id', $i->id)->first();
                $unitPrice = (float) ($pItem?->price_offer ?? $i->catalogue->price ?? 0);
                $qty       = (float) ($i->qty ?? 1);
                return [
                    'id'         => $i->id,
                    'nama'       => $i->catalogue->name ?? 'Barang',
                    'qty'        => $qty,
                    'unit_price' => $unitPrice,
                    'uom'        => $i->catalogue->uom ?? 'Pc',
                    'total'      => round($qty * $unitPrice),
                ];
            })->values()->toArray();
        }

        // Fallback: satu baris dari total PO
        if (empty($items)) {
            $items = [[
                'id'         => 'fallback',
                'nama'       => 'Barang/Jasa - PO ' . $po->po_number,
                'qty'        => 1,
                'unit_price' => (float) ($po->total_amount ?? 0),
                'uom'        => 'Unit',
                'total'      => (float) ($po->total_amount ?? 0),
            ]];
        }

        return response()->json([
            'po_number' => $po->po_number,
            'items'     => $items,
        ]);
    }

    /**
     * Terbitkan e-Faktur baru dari sebuah BAST yang sudah completed.
     * POST /api/efaktur
     */
    public function store(StoreEFakturRequest $request, CreateEFakturAction $action): JsonResponse
    {
        $bast = Bast::with(['purchaseOrder', 'vendorCompany', 'buyerCompany'])->findOrFail($request->bast_id);
        $po   = $bast->purchaseOrder;

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan untuk BAST ini.'], 422);
        }

        // Cegah duplikat
        $existing = EFaktur::where('bast_id', $bast->id)->first();
        if ($existing) {
            return response()->json([
                'message' => 'e-Faktur sudah pernah diterbitkan untuk BAST ini.',
                'efaktur' => $existing,
            ], 200);
        }

        try {
            $efaktur = $action->execute(
                $bast,
                $po,
                $request->only(['signer_name', 'signer_jabatan', 'signer_npwp', 'signer_kota'])
            );

            return response()->json([
                'message' => 'e-Faktur berhasil diterbitkan.',
                'efaktur' => $efaktur,
            ], 201);

        } catch (\Exception $e) {
            Log::error('EFakturController.store error', [
                'bast_id' => $request->bast_id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * List e-Faktur keluaran milik company.
     * GET /api/efaktur?company_id=xxx&page=1&per_page=15
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['message' => 'company_id diperlukan.'], 422);
        }

        $efakturs = EFaktur::with(['purchaseOrder', 'bast'])
            ->where('vat_type', 'VAT_OUT')
            ->whereHas('purchaseOrder', function ($q) use ($companyId) {
                $q->where('vendor_id', $companyId)
                  ->orWhere('buyer_company_id', $companyId);
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($efakturs);
    }

    /**
     * Detail satu e-Faktur, dengan refresh status dari PajakExpress list jika perlu.
     * GET /api/efaktur/{id}
     */
    public function show(string $id): JsonResponse
    {
        $efaktur = EFaktur::with(['purchaseOrder', 'bast', 'invoice'])->findOrFail($id);

        // Jika masih DRAFT dan ada pajak_express_id, coba refresh status dari list
        if ($efaktur->isDraft() && $efaktur->pajak_express_id) {
            try {
                $listRes  = $this->pajakExpress->listVatOut(1, 50);
                $items    = $listRes['data'] ?? [];
                $found    = collect($items)->firstWhere('id', $efaktur->pajak_express_id);

                if ($found) {
                    $newStatus = strtoupper($found['statusfaktur'] ?? $efaktur->status);
                    $nofa      = $found['nomorfaktur'] ?? $efaktur->nofa;

                    if ($newStatus !== $efaktur->status || $nofa !== $efaktur->nofa) {
                        $efaktur->update(['status' => $newStatus, 'nofa' => $nofa]);
                        $efaktur->refresh();
                    }
                }
            } catch (\Exception $e) {
                Log::warning('EFakturController.show: refresh status failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['efaktur' => $efaktur]);
    }

    /**
     * Upload faktur DRAFT ke DJP untuk mendapat nomor faktur resmi.
     * POST /api/efaktur/{id}/upload
     */
    public function upload(UploadEFakturRequest $request, string $id): JsonResponse
    {
        $efaktur = EFaktur::findOrFail($id);

        if (!$efaktur->pajak_express_id) {
            return response()->json(['message' => 'pajak_express_id tidak tersedia.'], 422);
        }

        if (!$efaktur->isDraft()) {
            return response()->json(['message' => "Hanya faktur berstatus DRAFT yang bisa diupload. Status saat ini: {$efaktur->status}"], 422);
        }

        try {
            $result = $this->pajakExpress->uploadVatOut(
                (int) $efaktur->pajak_express_id,
                $request->tempat_penandatangan,
                $request->npwp_nik_penandatangan
            );

            // PajakExpress mengembalikan HTTP 200 bahkan saat error — cek field status/code
            $isError = ($result['status'] ?? '') === 'error'
                || (isset($result['code']) && (int) $result['code'] === 0);

            if ($isError) {
                $errMsg = $result['message'] ?? 'Upload ke DJP gagal.';

                // Pesan passphrase lebih informatif
                if (stripos($errMsg, 'passphrase') !== false) {
                    preg_match('/npwp\s+([0-9]+)/i', $errMsg, $m);
                    $npwp = $m[1] ?? $request->npwp_nik_penandatangan;
                    $errMsg = "Passphrase belum dibuat untuk NPWP {$npwp}. "
                        . "Silakan login ke portal PajakExpress dan buat passphrase untuk NPWP tersebut sebelum upload.";
                }

                // Tetap simpan raw_response untuk audit trail
                $efaktur->update([
                    'raw_response' => array_merge($efaktur->raw_response ?? [], ['upload' => $result]),
                ]);

                Log::warning('EFakturController.upload: PajakExpress error response', [
                    'id'       => $id,
                    'message'  => $result['message'] ?? null,
                    'code'     => $result['code'] ?? null,
                ]);

                return response()->json(['message' => $errMsg], 422);
            }

            $nomorFaktur  = $result['data']['nomorFaktur']  ?? $efaktur->nofa;
            $statusFaktur = strtoupper($result['data']['statusFaktur'] ?? 'APPROVED');

            $efaktur->update([
                'nofa'         => $nomorFaktur,
                'status'       => $statusFaktur,
                'raw_response' => array_merge($efaktur->raw_response ?? [], ['upload' => $result]),
            ]);

            return response()->json([
                'message' => 'Faktur berhasil diupload ke DJP.',
                'efaktur' => $efaktur->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('EFakturController.upload error', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Cancel faktur yang sudah APPROVED.
     * POST /api/efaktur/{id}/cancel
     */
    public function cancel(string $id): JsonResponse
    {
        $efaktur = EFaktur::findOrFail($id);

        if (!$efaktur->nofa) {
            return response()->json(['message' => 'Nomor faktur belum tersedia, tidak bisa cancel.'], 422);
        }

        if ($efaktur->isCancelled()) {
            return response()->json(['message' => 'Faktur sudah dibatalkan.'], 422);
        }

        try {
            $npwpPenjual = $efaktur->npwp_penjual
                ?? config('services.pajak_express.npwp', '0717166367077000');

            $result = $this->pajakExpress->cancelVatOut(
                $efaktur->kd_jenis_transaksi ?? 'TD.00304',
                $efaktur->nofa,
                $npwpPenjual
            );

            $efaktur->update([
                'status'       => 'CANCELLED',
                'raw_response' => array_merge($efaktur->raw_response ?? [], ['cancel' => $result]),
            ]);

            return response()->json([
                'message' => 'e-Faktur berhasil dibatalkan.',
                'result'  => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Hapus draft faktur dari PajakExpress dan database lokal.
     * DELETE /api/efaktur/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $efaktur = EFaktur::findOrFail($id);

        if (!$efaktur->isDraft()) {
            return response()->json(['message' => 'Hanya draft yang bisa dihapus.'], 422);
        }

        try {
            if ($efaktur->pajak_express_id) {
                $this->pajakExpress->deleteVatOut($efaktur->pajak_express_id);
            }
            $efaktur->delete();

            return response()->json(['message' => 'Draft faktur berhasil dihapus.']);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * List faktur keluaran langsung dari PajakExpress (tanpa filter company lokal).
     * GET /api/efaktur/vat-out/list?page=1&limit=20
     */
    public function vatOutList(Request $request): JsonResponse
    {
        try {
            $result = $this->pajakExpress->listVatOut(
                (int) $request->query('page', 1),
                (int) $request->query('limit', 20)
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /* ═══════════════════════════════════════════════════════════════ */
    /*  VAT IN — Faktur Pajak Masukan                                  */
    /* ═══════════════════════════════════════════════════════════════ */

    /**
     * Get list faktur masukan dari PajakExpress.
     * GET /api/efaktur/vat-in?page=1&limit=20&periode=MM/YYYY
     */
    public function vatInList(Request $request): JsonResponse
    {
        try {
            $result = $this->pajakExpress->listVatIn(
                (int) $request->query('page', 1),
                (int) $request->query('limit', 20),
                (string) $request->query('periode', '')
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Inquiry prepopulated faktur masukan dari DJP.
     * POST /api/efaktur/vat-in/prepopulated
     */
    public function vatInPrepopulated(VatInPrepopulatedRequest $request): JsonResponse
    {
        try {
            $result = $this->pajakExpress->prepopulatedVatIn(
                $request->tahun_pajak,
                $request->masa_pajak,
                $request->npwp_penjual  ?? '',
                $request->nomor_faktur  ?? ''
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Konfirmasi pengkreditan faktur masukan.
     * POST /api/efaktur/vat-in/upload
     */
    public function vatInUpload(VatInUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->pajakExpress->uploadVatIn(
                $request->nomor_faktur,
                $request->masa_pajak,
                $request->tahun_pajak,
                (int) $request->input('konfirmasi_pengkreditan', 1)
            );

            // Simpan ke tabel lokal sebagai referensi
            EFaktur::create([
                'vat_type'    => 'VAT_IN',
                'nofa'        => $request->nomor_faktur,
                'status'      => strtoupper($result['data']['statusfaktur'] ?? 'APPROVED'),
                'npwp_penjual'=> $result['data']['npwppenjual'] ?? null,
                'no_invoice'  => $result['data']['referensi']   ?? $request->nomor_faktur,
                'masa_pajak'  => $request->masa_pajak,
                'tahun_pajak' => $request->tahun_pajak,
                'tanggal_faktur' => isset($result['data']['tanggalfaktur'])
                    ? date('Y-m-d', strtotime($result['data']['tanggalfaktur']))
                    : now()->toDateString(),
                'dpp'          => (float) ($result['data']['totaldpp'] ?? 0),
                'ppn'          => (float) ($result['data']['totalppn'] ?? 0),
                'raw_response' => $result,
            ]);

            return response()->json([
                'message' => 'Faktur masukan berhasil dikreditkan.',
                'result'  => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Verifikasi faktur masukan.
     * POST /api/efaktur/vat-in/verify
     */
    public function vatInVerify(VatInVerifyRequest $request): JsonResponse
    {
        try {
            $result = $this->pajakExpress->verifyVatIn(
                $request->tahun_pajak,
                $request->masa_pajak,
                $request->npwp_penjual  ?? '',
                $request->nomor_faktur  ?? ''
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
