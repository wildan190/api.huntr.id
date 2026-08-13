<?php

namespace App\Domain\EFaktur\Actions;

use App\Domain\EFaktur\Models\EFaktur;
use App\Domain\EFaktur\Services\PajakExpressService;
use App\Domain\Order\Models\Bast;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\Log;

/**
 * CreateEFakturAction
 *
 * Membuat Faktur Pajak Keluaran (VAT Out) via PajakExpress dalam dua langkah:
 *   1. POST /IF_TXR_001/create  — buat draft, dapat pajak_express_id
 *   2. POST /IF_TXR_001/upload  — upload ke DJP, dapat nomorFaktur resmi
 */
class CreateEFakturAction
{
    public function __construct(
        private readonly PajakExpressService $pajakExpress
    ) {}

    /**
     * @param  Bast          $bast
     * @param  PurchaseOrder $po
     * @param  array         $extra  Override signer: signer_name, signer_jabatan, signer_npwp
     * @return EFaktur
     */
    public function execute(Bast $bast, PurchaseOrder $po, array $extra = []): EFaktur
    {
        $po->load(['invoices', 'historicalItems', 'rfq.items', 'vendor', 'buyer']);
        $bast->load(['vendorCompany', 'buyerCompany']);

        $vendor = $po->vendor ?? $bast->vendorCompany;
        $buyer  = $po->buyer  ?? $bast->buyerCompany;

        // Pilih invoice final, fallback proforma
        $invoice = $po->invoices
            ->where('type', 'final')
            ->whereIn('status', ['unpaid', 'paid', 'pending_finance', 'disbursing'])
            ->first()
            ?? $po->invoices->where('type', 'proforma')->first();

        $baseAmount = (float) ($invoice?->base_amount ?? $invoice?->amount ?? $po->total_amount ?? 0);

        $tanggal  = now()->format('dmY');          // format PajakExpress: ddmmyyyy
        $masa     = now()->format('m');             // 01–12
        $tahun    = now()->format('Y');

        // Normalize NPWP → 16 digit
        $vendorNpwp = $this->normalizeNpwp($vendor?->npwp ?? '', config('services.pajak_express.npwp', '0717166367077000'));
        $buyerNpwp  = $this->normalizeNpwp($buyer?->npwp  ?? '', '1091031210912281');

        // TKU = NPWP 16 digit + 000000
        $vendorTku = $vendorNpwp . '000000';
        $buyerTku  = $buyerNpwp  . '000000';

        // Build objekFaktur
        $objekFaktur = $this->buildObjekFaktur($po, $baseAmount);
        $totalDpp    = collect($objekFaktur)->sum(fn($i) => (float) $i['dpp']);
        $totalDppLain= collect($objekFaktur)->sum(fn($i) => (float) ($i['dppLain'] ?? $i['dpp']));
        $totalPpn    = collect($objekFaktur)->sum(fn($i) => (float) $i['ppn']);

        // Signer info (penandatangan)
        $signerNpwp   = $this->normalizeNpwp($extra['signer_npwp'] ?? $vendorNpwp, $vendorNpwp);
        $signerKota   = $extra['signer_kota'] ?? $vendor?->city ?? 'Jakarta';

        // ── Step 1: Create draft ───────────────────────────────────────
        $createPayload = [
            'fgUangMuka'            => false,
            'fgPelunasan'           => false,
            'nomorFaktur'           => '',
            'nomorFakturDiganti'    => '',
            'detailTransaksi'       => 'TD.00304',
            'idKeteranganTambahan'  => '',
            'keteranganTambahan'    => '',
            'masaPajak'             => $masa,
            'tahunPajak'            => $tahun,
            'refDoc'                => '',
            'referensi'             => $po->po_number,
            'namaTokoPenjual'       => $vendorNpwp . '000000',   // NPWP + 6 zeros
            'npwpPembeli'           => $buyerNpwp,
            'idLainPembeli'         => '',
            'kdNegaraPembeli'       => 'IDN',
            'nikPaspPembeli'        => '',
            'namaPembeli'           => $buyer?->name ?? 'Pembeli',
            'tkuPembeli'            => $buyerTku,
            'alamatPembeli'         => $buyer?->address ?? 'Jakarta',
            'emailPembeli'          => $buyer?->email ?? '',
            'keterangan1'           => '',
            'keterangan2'           => '',
            'keterangan3'           => '',
            'keterangan4'           => '',
            'keterangan5'           => '',
            'objekFaktur'           => $objekFaktur,
            'jumlahUangMuka'        => '0',
            'totalDpp'              => (string) round($totalDpp),
            'totalDppLain'          => (string) round($totalDppLain),
            'totalPpn'              => (string) round($totalPpn),
            'totalPpnbm'            => '0',
            'tanggalFaktur'         => $tanggal,
            'approvalSign'          => '',
            'fgPengganti'           => '0',
            'capKetTambahan'        => '',
        ];

        Log::info('CreateEFakturAction: Step 1 — create draft', [
            'bast_id'   => $bast->id,
            'po_number' => $po->po_number,
        ]);

        $createResponse = $this->pajakExpress->createVatOut($createPayload);

        $pajakExpressId = (int) ($createResponse['data']['id'] ?? 0);
        $statusFaktur   = $createResponse['data']['statusFaktur'] ?? 'DRAFT';

        if (!$pajakExpressId) {
            throw new \RuntimeException('PajakExpress tidak mengembalikan ID faktur dari step create.');
        }

        // ── Step 2: Upload ke DJP ─────────────────────────────────────
        Log::info('CreateEFakturAction: Step 2 — upload to DJP', [
            'pajak_express_id' => $pajakExpressId,
        ]);

        $uploadResponse = $this->pajakExpress->uploadVatOut(
            $pajakExpressId,
            $signerKota,
            $signerNpwp
        );

        $nomorFaktur  = $uploadResponse['data']['nomorFaktur']  ?? null;
        $statusFaktur = $uploadResponse['data']['statusFaktur'] ?? $statusFaktur;

        // ── Persist ───────────────────────────────────────────────────
        return EFaktur::create([
            'bast_id'           => $bast->id,
            'po_id'             => $po->id,
            'invoice_id'        => $invoice?->id,
            'pajak_express_id'  => (string) $pajakExpressId,
            'nofa'              => $nomorFaktur,
            'status'            => strtoupper($statusFaktur),
            'no_invoice'        => $po->po_number,
            'masa_pajak'        => $masa,
            'tahun_pajak'       => $tahun,
            'tanggal_faktur'    => now()->toDateString(),
            'dpp'               => round($totalDpp, 2),
            'ppn'               => round($totalPpn, 2),
            'raw_request'       => $createPayload,
            'raw_response'      => [
                'create' => $createResponse,
                'upload' => $uploadResponse,
            ],
        ]);
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  Helpers                                                         */
    /* ─────────────────────────────────────────────────────────────── */

    /**
     * Normalize NPWP: strip non-digit, pad/trim to 16 chars.
     */
    private function normalizeNpwp(string $raw, string $fallback): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($digits) < 10) {
            $digits = preg_replace('/[^0-9]/', '', $fallback);
        }
        return str_pad(substr($digits, 0, 16), 16, '0', STR_PAD_RIGHT);
    }

    /**
     * Build objekFaktur array sesuai format PajakExpress IF_TXR_001/create.
     * Tarif PPN 12% dengan DPP Lain = 11/12 × Harga (PMK-131/2024).
     */
    private function buildObjekFaktur(PurchaseOrder $po, float $baseAmount): array
    {
        $items = null;

        if ($po->is_historical && $po->historicalItems?->isNotEmpty()) {
            $items = $po->historicalItems;
        } elseif ($po->rfq?->items?->isNotEmpty()) {
            $items = $po->rfq->items;
        }

        if (!$items || $items->isEmpty()) {
            return [$this->buildSingleLine($po->po_number, $baseAmount)];
        }

        return $items->map(function ($item) use ($po) {
            if ($po->is_historical) {
                $qty       = (float) ($item->qty ?? 1);
                $unitPrice = (float) ($item->unit_price ?? 0);
                $nama      = $item->inventory_name ?? 'Barang';
                $kode      = $item->inventory_code  ?? '000000';
            } else {
                $qty       = (float) ($item->qty ?? 1);
                $nama      = $item->catalogue->name      ?? 'Barang';
                $kode      = $item->catalogue->item_code ?? '000000';
                $proposal  = $po->rfq->proposals->where('status', 'accepted')->first();
                $pItem     = $proposal?->items->where('rfq_item_id', $item->id)->first();
                $unitPrice = (float) ($pItem?->price_offer ?? $item->catalogue->price ?? 0);
            }

            $totalHarga = round($qty * $unitPrice);
            return $this->makeObjekLine($nama, $kode, $qty, $unitPrice, $totalHarga);
        })->toArray();
    }

    private function buildSingleLine(string $poNumber, float $baseAmount): array
    {
        return $this->makeObjekLine(
            "Barang/Jasa - PO {$poNumber}",
            '000000',
            1,
            $baseAmount,
            $baseAmount
        );
    }

    /**
     * Satu baris objekFaktur dengan kalkulasi DPP Lain (PMK-131/2024):
     *   DPP Lain = totalHarga × 11/12
     *   PPN      = DPP Lain × 12%  =  totalHarga × 11%
     */
    private function makeObjekLine(
        string $nama,
        string $kode,
        float  $jumlah,
        float  $harga,
        float  $totalHarga
    ): array {
        $dpp      = $totalHarga;
        $dppLain  = round($totalHarga * 11 / 12, 2);
        $ppn      = round($dppLain * 0.12, 2);   // 12% dari DPP Lain = 11% dari harga

        return [
            'brgJasa'       => 'GOODS',
            'kdBrgJasa'     => $kode,
            'namaBrgJasa'   => $nama,
            'satuanBrgJasa' => 'UM.0001',
            'hargaSatuan'   => (string) round($harga),
            'jmlBrgJasa'    => (string) $jumlah,
            'totalHarga'    => (string) round($totalHarga),
            'diskon'        => '0',
            'cekDppLain'    => true,
            'dpp'           => (string) round($dpp),
            'dppLain'       => (string) $dppLain,
            'tarifPpn'      => '12',
            'ppn'           => (string) round($ppn),
            'tarifPpnbm'    => '0',
            'ppnbm'         => '0',
            'fgPmk'         => '0',
        ];
    }
}
