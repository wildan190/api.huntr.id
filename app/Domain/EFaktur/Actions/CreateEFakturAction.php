<?php

namespace App\Domain\EFaktur\Actions;

use App\Domain\EFaktur\Models\EFaktur;
use App\Domain\EFaktur\Services\PajakIoService;
use App\Domain\Order\Models\Bast;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\Log;

class CreateEFakturAction
{
    public function __construct(
        private readonly PajakIoService $pajakIo
    ) {}

    /**
     * Create an e-Faktur (Pajak Keluaran) after BAST is issued.
     * The vendor (PKP) issues the faktur to the buyer (lawan transaksi).
     *
     * @param Bast          $bast
     * @param PurchaseOrder $po
     * @param array         $extra  Override/extend payload fields (e.g. penandatangan)
     * @return EFaktur
     */
    public function execute(Bast $bast, PurchaseOrder $po, array $extra = []): EFaktur
    {
        // Load relationships (note: PO doesn't have direct 'items', only historicalItems or rfq.items)
        $po->load(['invoices', 'historicalItems', 'rfq.items', 'vendor', 'buyer']);
        $bast->load(['vendorCompany', 'buyerCompany']);

        $vendor = $po->vendor ?? $bast->vendorCompany;
        $buyer  = $po->buyer  ?? $bast->buyerCompany;

        // Use final invoice if available, else proforma
        $invoice = $po->invoices
            ->where('type', 'final')
            ->whereIn('status', ['unpaid', 'paid', 'pending_finance', 'disbursing'])
            ->first()
            ?? $po->invoices->where('type', 'proforma')->first();

        $baseAmount = (float) ($invoice?->base_amount ?? $invoice?->amount ?? $po->total_amount ?? 0);
        $tanggal    = now()->format('Y-m-d');
        $masa       = now()->format('m');
        $tahun      = now()->format('Y');

        // Build barangJasa from PO items
        $items = $this->buildBarangJasa($po, $baseAmount);

        // Calculate DPP and PPN
        $totalDpp = collect($items)->sum('dpp');
        $totalPpn = collect($items)->sum('ppn');

        // Normalize NPWPs to 16 digits
        $vendorNpwpRaw = preg_replace('/[^0-9]/', '', $vendor?->npwp ?? '');
        $vendorNpwp16 = str_pad($vendorNpwpRaw ?: '1305202311840002', 16, '0', STR_PAD_LEFT);

        $buyerNpwpRaw = preg_replace('/[^0-9]/', '', $buyer?->npwp ?? '');
        $buyerNpwp16 = str_pad($buyerNpwpRaw ?: '1091031210911629', 16, '0', STR_PAD_LEFT);

        // Lawan transaksi = buyer company
        $lawanTransaksi = [
            'identityType'  => 'NPWP',
            'identityValue' => $buyerNpwp16,
            'nama'          => $buyer?->name ?? 'Kongsi Tirta',
            'nitku'         => $buyerNpwp16 . '000000',
            'telp'          => $buyer?->phone ?? '0218720712',
            'kodeNegara'    => 'IDN',
            'alamatJalan'   => $buyer?->address ?? 'Jakarta',
            'kota'          => $buyer?->city ?? 'DKI Jakarta',
            'email'         => $buyer?->email ?? '',
        ];

        // Penandatangan (signer) = from vendor company or defaults
        $penandatangan = array_merge([
            'npwp'       => $vendorNpwp16,
            'passphrase' => config('services.pajakio.passphrase', 'test'),
            'nama'       => $extra['signer_name'] ?? ($vendor?->owner_name ?? 'FERRY IRAWAN'),
            'kota'       => $vendor?->city ?? 'DKI Jakarta',
            'jabatan'    => $extra['signer_jabatan'] ?? 'CEO',
        ], $extra['penandatangan'] ?? []);

        $payload = [
            'autoUploadDjp'       => true, // auto upload to DJP
            'pengganti'           => false,
            'nofaDiganti'         => '',
            'kdJenisTransaksi'    => 'TD.00301', // Penjualan H2H / B2B biasa
            'keteranganTambahan'  => [
                'kode'                   => '',
                'nomorDokumenPendukung'  => $bast->bast_number,
            ],
            'masaPajak'           => $masa,
            'tahunPajak'          => $tahun,
            'tanggalFaktur'       => $tanggal,
            'noInvoice'           => $po->po_number,
            'barangJasa'          => $items,
            'lawanTransaksi'      => $lawanTransaksi,
            'terminPembayaran'    => [
                'type'  => 'NORMAL',
                'dpp'   => 0,
                'ppn'   => 0,
                'ppnbm' => 0,
                'nofa'  => '',
            ],
            'totalDpp'            => $totalDpp,
            'totalDppLain'        => $totalDpp,
            'totalPpn'            => $totalPpn,
            'totalPpnBm'          => 0,
            'penandatangan'       => $penandatangan,
            'pembuatFaktur'       => [
                'npwp' => $vendorNpwp16,
                'nama' => $penandatangan['nama'],
            ],
            'nitkuPkp'            => [
                'nitku' => $vendorNpwp16 . '000000',
                'nama'  => 'HO',
            ],
        ];

        // Merge any extra top-level payload overrides
        if (isset($extra['payload'])) {
            $payload = array_merge($payload, $extra['payload']);
        }

        Log::info('CreateEFakturAction: sending payload to Pajak.io', [
            'bast_id'   => $bast->id,
            'po_number' => $po->po_number,
            'no_invoice' => $payload['noInvoice'],
        ]);

        // Call Pajak.io sandbox API
        $response = $this->pajakIo->createFaktur($payload);

        // Get status, transactionId, and nofa with proper clean-up from API response structure
        $resCode = $response['code'] ?? null;
        $resData = $response['data'] ?? [];
        
        $transactionId = $resData['transactionId'] ?? $response['transactionId'] ?? null;
        $nofa = $resData['nofa'] ?? $response['nofa'] ?? null;
        
        // Map successful creation status
        $status = 'CREATED';
        if (isset($response['status']) && strtoupper($response['status']) === 'OK') {
            $status = 'APPROVED'; // Mark approved if transactionId returned successfully in sandbox
        }

        // Persist record
        return EFaktur::create([
            'bast_id'        => $bast->id,
            'po_id'          => $po->id,
            'invoice_id'     => $invoice?->id,
            'nofa'           => $nofa,
            'transaction_id' => $transactionId,
            'status'         => $status,
            'no_invoice'     => $po->po_number,
            'masa_pajak'     => $masa,
            'tahun_pajak'    => $tahun,
            'tanggal_faktur' => $tanggal,
            'dpp'            => $totalDpp,
            'ppn'            => $totalPpn,
            'raw_request'    => $payload,
            'raw_response'   => $response,
        ]);
    }

    /**
     * Build barangJasa array from PO items.
     * Falls back to a single summary line if no items.
     */
    private function buildBarangJasa(PurchaseOrder $po, float $baseAmount): array
    {
        // Get items from either historicalItems or rfq.items
        $items = null;
        
        if ($po->is_historical && $po->historicalItems && $po->historicalItems->isNotEmpty()) {
            $items = $po->historicalItems;
        } elseif ($po->rfq && $po->rfq->items && $po->rfq->items->isNotEmpty()) {
            $items = $po->rfq->items;
        }

        if (!$items || $items->isEmpty()) {
            // Fallback single-line faktur from base amount
            $dpp = $baseAmount;
            $ppn = round($dpp * 0.11);

            return [[
                'jenis'       => 'BARANG',
                'nama'        => 'Barang/Jasa - PO ' . $po->po_number,
                'kode'        => '000000',
                'jumlah'      => 1,
                'kodeSatuan'  => 'UM.0021',
                'harga'       => $dpp,
                'totalHarga'  => (string) $dpp,
                'diskon'      => 0,
                'tarifPpn'    => 11,
                'dpp'         => $dpp,
                'cekDppLain'  => false,
                'dppLain'     => $dpp,
                'ppn'         => $ppn,
                'tarifPpnbm'  => 0,
                'ppnbm'       => 0,
            ]];
        }

        return $items->map(function ($item) use ($po) {
            // Handle both historicalItems and rfq.items
            if ($po->is_historical) {
                // Historical item structure
                $qty       = (float) ($item->qty ?? 1);
                $unitPrice = (float) ($item->unit_price ?? 0);
                $itemName  = $item->inventory_name ?? 'Barang';
                $itemCode  = $item->inventory_code ?? '000000';
            } else {
                // RFQ item structure - need to get price from accepted proposal
                $qty       = (float) ($item->qty ?? 1);
                $itemName  = $item->catalogue->name ?? 'Barang';
                $itemCode  = $item->catalogue->item_code ?? '000000';
                
                // Get unit price from proposal (if available)
                $proposal = $po->rfq->proposals->where('status', 'accepted')->first();
                $proposalItem = $proposal ? $proposal->items->where('rfq_item_id', $item->id)->first() : null;
                $unitPrice = (float) ($proposalItem->price_offer ?? $item->catalogue->price ?? 0);
            }
            
            $total     = round($qty * $unitPrice);
            $ppn       = round($total * 0.11);

            return [
                'jenis'       => 'BARANG',
                'nama'        => $itemName,
                'kode'        => $itemCode,
                'jumlah'      => $qty,
                'kodeSatuan'  => 'UM.0021',
                'harga'       => $unitPrice,
                'totalHarga'  => (string) $total,
                'diskon'      => 0,
                'tarifPpn'    => 11,
                'dpp'         => $total,
                'cekDppLain'  => false,
                'dppLain'     => $total,
                'ppn'         => $ppn,
                'tarifPpnbm'  => 0,
                'ppnbm'       => 0,
            ];
        })->toArray();
    }
}
