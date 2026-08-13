<?php

namespace App\Domain\EFaktur\Http\Controllers;

use App\Domain\EFaktur\Actions\CreateEFakturAction;
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
     * Terbitkan e-Faktur baru dari sebuah BAST yang sudah completed.
     * POST /api/efaktur
     */
    public function store(Request $request, CreateEFakturAction $action): JsonResponse
    {
        $request->validate([
            'bast_id'        => 'required|uuid|exists:basts,id',
            'signer_name'    => 'nullable|string|max:255',
            'signer_jabatan' => 'nullable|string|max:255',
            'signer_npwp'    => 'nullable|string|max:20',
            'signer_kota'    => 'nullable|string|max:100',
        ]);

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
    public function upload(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tempat_penandatangan'  => 'required|string|max:100',
            'npwp_nik_penandatangan'=> 'required|string|max:20',
        ]);

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
    public function vatInPrepopulated(Request $request): JsonResponse
    {
        $request->validate([
            'tahun_pajak'    => 'required|string|size:4',
            'masa_pajak'     => 'required|string',
            'npwp_penjual'   => 'nullable|string',
            'nomor_faktur'   => 'nullable|string',
        ]);

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
    public function vatInUpload(Request $request): JsonResponse
    {
        $request->validate([
            'nomor_faktur'              => 'required|string',
            'masa_pajak'                => 'required|string',
            'tahun_pajak'               => 'required|string|size:4',
            'konfirmasi_pengkreditan'   => 'nullable|integer|in:0,1',
        ]);

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
    public function vatInVerify(Request $request): JsonResponse
    {
        $request->validate([
            'tahun_pajak'    => 'required|string|size:4',
            'masa_pajak'     => 'required|string',
            'npwp_penjual'   => 'nullable|string',
            'nomor_faktur'   => 'nullable|string',
        ]);

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
