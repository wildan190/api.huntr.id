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

    /* ─── Cached reference lookup ────────────────────────────────── */

    private ?array $goodsRef   = null;
    private ?array $serviceRef = null;
    private ?array $satuanRef  = null;

    /**
     * Cari kode barang DJP yang paling cocok dengan nama produk.
     * Fallback ke '000000' (Barang generic) jika tidak ada match.
     */
    private function lookupGoodsCode(string $nama): string
    {
        if ($this->goodsRef === null) {
            $this->goodsRef = $this->pajakExpress->getReference('goods');
        }

        $namaLower = mb_strtolower($nama);

        // Keywords sederhana → kode yang tepat
        $keywordMap = [
            'spare part'    => '840000',
            'sparepart'     => '840000',
            'mesin'         => '840000',
            'machine'       => '840000',
            'equipment'     => '840000',
            'alat'          => '820000',
            'elektronik'    => '850000',
            'electronic'    => '850000',
            'besi'          => '730000',
            'baja'          => '730000',
            'steel'         => '730000',
            'pipa'          => '730000',
            'pipe'          => '730000',
            'cable'         => '850000',
            'kabel'         => '850000',
            'chemical'      => '380000',
            'kimia'         => '380000',
            'safety'        => '620000',
            'helm'          => '620000',
            'seragam'       => '620000',
            'baju'          => '620000',
            'kertas'        => '480000',
            'paper'         => '480000',
            'atk'           => '480000',
            'komputer'      => '847000',
            'laptop'        => '847000',
            'computer'      => '847000',
            'oli'           => '271000',
            'pelumas'       => '271000',
            'fuel'          => '271000',
            'bbm'           => '271000',
            'ban'           => '401100',
            'tyre'          => '401100',
            'bearing'       => '840000',
            'valve'         => '840000',
            'pump'          => '840000',
            'pompa'         => '840000',
            'hydraulic'     => '840000',
            'hidrolik'      => '840000',
        ];

        foreach ($keywordMap as $keyword => $code) {
            if (str_contains($namaLower, $keyword)) {
                return $code;
            }
        }

        // Cari di reference list berdasarkan kesamaan kata kunci bahasa Indonesia
        $words = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/i', ' ', $namaLower)));
        foreach ($words as $word) {
            if (strlen($word) < 4) continue;
            foreach ($this->goodsRef as $item) {
                $desc = mb_strtolower($item['bahasa'] ?? '');
                if (str_contains($desc, $word)) {
                    return $item['code'];
                }
            }
        }

        return '000000'; // Barang generic — selalu valid
    }

    /**
     * Map UOM internal (Pc, Kg, L, M, dll) ke kode satuan PajakExpress.
     */
    private function lookupSatuanCode(string $uom): string
    {
        if ($this->satuanRef === null) {
            $this->satuanRef = $this->pajakExpress->getSatuanReference();
        }

        // Cari exact match (case-insensitive) di deskripsi satuan
        $uomLower = mb_strtolower(trim($uom));
        foreach ($this->satuanRef as $s) {
            if (mb_strtolower($s['description'] ?? '') === $uomLower) {
                return $s['code'];
            }
        }

        // Fallback map untuk UOM umum — berdasarkan reference aktual PajakExpress
        $map = [
            'pc'        => 'UM.0021', // Piece
            'pcs'       => 'UM.0021',
            'piece'     => 'UM.0021',
            'unit'      => 'UM.0018', // Unit
            'set'       => 'UM.0019', // Set
            'kg'        => 'UM.0003', // Kilogram
            'kilogram'  => 'UM.0003',
            'gram'      => 'UM.0004', // Gram
            'ton'       => 'UM.0001', // Metrik Ton
            'l'         => 'UM.0007', // Liter
            'liter'     => 'UM.0007',
            'litre'     => 'UM.0007',
            'kl'        => 'UM.0006', // Kiloliter
            'm'         => 'UM.0013', // Meter
            'meter'     => 'UM.0013',
            'm2'        => 'UM.0012', // Meter Persegi
            'm3'        => 'UM.0034', // Meter Kubik
            'cm'        => 'UM.0015', // Sentimeter
            'box'       => 'UM.0022', // Boks
            'drum'      => 'UM.0036', // Drum
            'roll'      => 'UM.0039', // Roll
            'karton'    => 'UM.0037', // Karton
            'carton'    => 'UM.0037',
            'sheet'     => 'UM.0020', // Lembar
            'lembar'    => 'UM.0020',
            'lusin'     => 'UM.0017', // Lusin
            'dozen'     => 'UM.0017',
            'pallet'    => 'UM.0018', // Unit (paling dekat)
            'pair'      => 'UM.0021', // Piece
            'kwh'       => 'UM.0038', // Kwh
            'barrel'    => 'UM.0008', // Barrel
            'inch'      => 'UM.0014', // Inches
            'yard'      => 'UM.0016', // Yard
        ];

        if (isset($map[$uomLower])) {
            return $map[$uomLower];
        }

        return 'UM.0021'; // Default: Piece
    }

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

        // Build objekFaktur — pakai items_override dari user jika ada
        if (!empty($extra['items_override'])) {
            $objekFaktur = $this->buildFromOverride($extra['items_override']);
        } else {
            $objekFaktur = $this->buildObjekFaktur($po, $baseAmount);
        }
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
                $uom       = $item->uom ?? 'Pc';
            } else {
                $qty       = (float) ($item->qty ?? 1);
                $nama      = $item->catalogue->name      ?? 'Barang';
                $uom       = $item->catalogue->uom       ?? 'Pc';
                $proposal  = $po->rfq->proposals->where('status', 'accepted')->first();
                $pItem     = $proposal?->items->where('rfq_item_id', $item->id)->first();
                $unitPrice = (float) ($pItem?->price_offer ?? $item->catalogue->price ?? 0);
            }

            $totalHarga = round($qty * $unitPrice);
            $kodeDJP    = $this->lookupGoodsCode($nama);
            $satuanDJP  = $this->lookupSatuanCode($uom);
            return $this->makeObjekLine($nama, $kodeDJP, $satuanDJP, $qty, $unitPrice, $totalHarga);
        })->toArray();
    }

    private function buildSingleLine(string $poNumber, float $baseAmount): array
    {
        return $this->makeObjekLine(
            "Barang/Jasa - PO {$poNumber}",
            '000000',
            'UM.0021',
            1,
            $baseAmount,
            $baseAmount
        );
    }

    /**
     * Build objekFaktur dari items_override yang dipilih user.
     * Setiap item sudah mengandung kd_brg dan satuan yang valid dari DJP.
     */
    private function buildFromOverride(array $overrides): array
    {
        return array_map(function (array $item) {
            $qty        = (float) ($item['qty'] ?? 1);
            $unitPrice  = (float) ($item['unit_price'] ?? 0);
            $totalHarga = round($qty * $unitPrice);

            return $this->makeObjekLine(
                $item['nama'] ?? 'Barang',
                $item['kd_brg'] ?? '000000',
                $item['satuan'] ?? 'UM.0021',
                $qty,
                $unitPrice,
                $totalHarga
            );
        }, $overrides);
    }

    /**
     * Satu baris objekFaktur dengan kalkulasi DPP Lain (PMK-131/2024).
     * Sanitize nama & kode barang agar kompatibel dengan PajakExpress API.
     */
    /**
     * Sanitize nama barang: strip karakter non-ASCII/non-Latin agar
     * PajakExpress tidak reject payload (API hanya terima ASCII).
     */
    private function sanitizeName(string $nama): string
    {
        // Transliterate ke ASCII, hapus yang tidak bisa dikonversi
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nama);
        // Hapus karakter di luar printable ASCII (32-126)
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $ascii ?: $nama);
        // Compress multiple spaces, trim
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        return $clean !== '' ? substr($clean, 0, 100) : 'Barang';
    }

    /**
     * Normalize kode barang ke format DJP.
     * kdBrgJasa harus '000000' (kode generic barang) karena item code
     * internal katalog bukan kode BKP/JKP yang diakui DJP.
     * Menggunakan kode selain '000000' menyebabkan ERR_GOODS_SERVICES_CODE_NOT_ALLOWABLE.
     */
    private function normalizeKodeBrg(string $kode): string
    {
        return '000000';
    }

    private function makeObjekLine(
        string $nama,
        string $kode,
        string $satuan,
        float  $jumlah,
        float  $harga,
        float  $totalHarga
    ): array {
        $dpp      = $totalHarga;
        $dppLain  = round($totalHarga * 11 / 12, 2);
        $ppn      = round($dppLain * 0.12, 2);

        return [
            'brgJasa'       => 'GOODS',
            'kdBrgJasa'     => $this->normalizeKodeBrg($kode),
            'namaBrgJasa'   => $this->sanitizeName($nama),
            'satuanBrgJasa' => $satuan,
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
