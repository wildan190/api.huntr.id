<?php

namespace App\Domain\EFaktur\Services;

use App\Domain\EFaktur\Models\EFaktur;

/**
 * Simulates the DJP approval-only portion of the e-Faktur workflow locally.
 * It is deliberately unavailable outside APP_ENV=local.
 */
class LocalEfakturSimulationService
{
    public function enabled(): bool
    {
        return app()->environment('local')
            && (bool) config('services.pajak_express.local_simulation', true);
    }

    public function approve(EFaktur $efaktur): EFaktur
    {
        $approvalSign = $this->approvalSign($efaktur);
        $nofa = $efaktur->nofa ?: sprintf(
            'SIM-%s-%s',
            now()->format('YmdHis'),
            strtoupper(substr(str_replace('-', '', $efaktur->id), 0, 6)),
        );
        $raw = $efaktur->raw_response ?? [];
        $simulation = [
            'environment' => 'local',
            'approved_at' => now()->toIso8601String(),
            'approvalSign' => $approvalSign,
            'notice' => 'Data simulasi lokal; bukan faktur pajak yang disetujui DJP.',
        ];

        $efaktur->update([
            'nofa' => $nofa,
            'status' => 'APPROVED',
            'raw_response' => array_merge($raw, [
                'local_simulation' => $simulation,
                'upload' => array_merge($raw['upload'] ?? [], [
                    'data' => array_merge($raw['upload']['data'] ?? [], [
                        'nomorFaktur' => $nofa,
                        'statusFaktur' => 'APPROVED',
                        'approvalSign' => $approvalSign,
                    ]),
                ]),
            ]),
        ]);

        return $efaktur->fresh();
    }

    public function verify(EFaktur $efaktur, string $operation): array
    {
        $efaktur = $this->approve($efaktur);
        $approvalSign = $this->approvalSign($efaktur);

        return [
            'message' => 'Verifikasi berhasil disimulasikan pada environment lokal.',
            'data' => [
                'nomorFaktur' => $efaktur->nofa,
                'statusFaktur' => 'APPROVED',
                'approvalSign' => $approvalSign,
                'operation' => $operation,
                'simulated' => true,
            ],
        ];
    }

    public function pdf(EFaktur $efaktur): array
    {
        return [
            'KdStatus' => '1',
            'MsgStatus' => 'PDF simulasi lokal berhasil dibuat.',
            'data' => ['arraybuff' => base64_encode($this->buildPdf($efaktur))],
            'simulated' => true,
        ];
    }

    public function vatInList(string $buyerCompanyId, int $page, int $limit, string $period = ''): array
    {
        $query = $this->approvedVatOutForBuyer($buyerCompanyId);
        $this->applyPeriod($query, $period);
        $total = $query->count();
        $items = $query->forPage(max(1, $page), max(1, $limit))->get();

        return [
            'data' => $items->map(fn (EFaktur $efaktur) => $this->vatInItem($efaktur))->values()->all(),
            'metaPage' => ['page' => max(1, $page), 'limit' => max(1, $limit), 'totalRow' => $total, 'simulated' => true],
        ];
    }

    public function prepopulatedVatIn(string $buyerCompanyId, string $year, string $month, string $sellerNpwp = '', string $invoiceNumber = ''): array
    {
        $query = $this->approvedVatOutForBuyer($buyerCompanyId)
            ->where('tahun_pajak', $year)
            ->where('masa_pajak', str_pad((string) (int) $month, 2, '0', STR_PAD_LEFT));
        if ($invoiceNumber !== '') {
            $query->where('nofa', 'like', '%' . str_replace('INV#', '', $invoiceNumber) . '%');
        }

        $items = $query->get()->filter(function (EFaktur $efaktur) use ($sellerNpwp) {
            return $sellerNpwp === '' || str_contains($this->sellerNpwp($efaktur), preg_replace('/\D/', '', $sellerNpwp));
        });

        return ['data' => ['dataFaktur' => $items->map(fn (EFaktur $efaktur) => $this->prepopulatedItem($efaktur))->values()->all()], 'simulated' => true];
    }

    public function creditVatIn(string $buyerCompanyId, string $invoiceNumber): array
    {
        $efaktur = $this->approvedVatOutForBuyer($buyerCompanyId)
            ->where('nofa', str_replace('INV#', '', $invoiceNumber))
            ->firstOrFail();
        $raw = $efaktur->raw_response ?? [];
        $simulation = $raw['local_simulation'] ?? [];
        $simulation['vat_in_buyer_status'] = 'CREDITED';
        $simulation['credited_at'] = now()->toIso8601String();
        $efaktur->update(['raw_response' => array_merge($raw, ['local_simulation' => $simulation])]);

        return ['message' => 'Faktur masukan berhasil dikreditkan pada simulasi lokal.', 'data' => $this->vatInItem($efaktur->fresh()), 'simulated' => true];
    }

    private function approvalSign(EFaktur $efaktur): string
    {
        return 'local://efaktur/' . $efaktur->id . '/approval-sign';
    }

    private function approvedVatOutForBuyer(string $buyerCompanyId)
    {
        return EFaktur::query()
            ->with('purchaseOrder.vendor', 'purchaseOrder.buyer')
            ->where('vat_type', 'VAT_OUT')
            ->where('status', 'APPROVED')
            ->whereHas('purchaseOrder', fn ($query) => $query->where('buyer_company_id', $buyerCompanyId))
            ->latest('tanggal_faktur');
    }

    private function applyPeriod($query, string $period): void
    {
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $period, $matches)) {
            $query->where('masa_pajak', str_pad((string) (int) $matches[1], 2, '0', STR_PAD_LEFT))->where('tahun_pajak', $matches[2]);
        }
    }

    private function vatInItem(EFaktur $efaktur): array
    {
        $simulation = ($efaktur->raw_response ?? [])['local_simulation'] ?? [];

        return [
            'id' => $efaktur->id,
            'nomorfaktur' => $efaktur->nofa,
            'namatokopenjual' => $efaktur->purchaseOrder?->vendor?->name ?? $efaktur->purchaseOrder?->vendor_name ?? 'Vendor',
            'npwppenjual' => $this->sellerNpwp($efaktur),
            'namapembeli' => $efaktur->purchaseOrder?->buyer?->name ?? 'Buyer',
            'masapajak' => $efaktur->masa_pajak,
            'tahunpajak' => $efaktur->tahun_pajak,
            'tanggalfaktur' => (string) $efaktur->tanggal_faktur,
            'totaldpp' => $efaktur->dpp,
            'totaldpplain' => $efaktur->dpp,
            'totalppn' => $efaktur->ppn,
            'totalppnbm' => 0,
            'statusfaktur' => 'APPROVED',
            'statuspembeli' => $simulation['vat_in_buyer_status'] ?? null,
            'buyerstatus' => $simulation['vat_in_buyer_status'] ?? null,
            'tanggalapproval' => $simulation['approved_at'] ?? null,
            'approvalsign' => $simulation['approvalSign'] ?? $this->approvalSign($efaktur),
            'referensi' => $efaktur->no_invoice,
        ];
    }

    private function prepopulatedItem(EFaktur $efaktur): array
    {
        $item = $this->vatInItem($efaktur);

        return [
            'SellerTIN' => $item['npwppenjual'], 'SellerTaxpayerName' => $item['namatokopenjual'],
            'BuyerStatus' => $item['buyerstatus'], 'SellingPrice' => $item['totaldpp'], 'VAT' => $item['totalppn'],
            'STLG' => 0, 'ReportedBySeller' => true, 'TaxInvoiceNumber' => $item['nomorfaktur'],
            'TaxInvoiceDate' => $item['tanggalfaktur'], 'TaxInvoicePeriod' => $item['masapajak'],
            'TaxInvoiceYear' => $item['tahunpajak'], 'TaxInvoiceStatus' => 'APPROVED',
            'OtherTaxBase' => $item['totaldpp'], 'Valid' => true, 'Recorder' => 'LOCAL_SIMULATION',
        ];
    }

    private function sellerNpwp(EFaktur $efaktur): string
    {
        return preg_replace('/\D/', '', (string) ($efaktur->purchaseOrder?->vendor?->tax_id ?? $efaktur->npwp_penjual ?? ''));
    }

    /** Creates a dependency-free A4 Faktur Pajak layout for local preview. */
    private function buildPdf(EFaktur $efaktur): string
    {
        $efaktur->loadMissing('purchaseOrder.vendor', 'purchaseOrder.buyer');
        $po = $efaktur->purchaseOrder;
        $vendor = $po?->vendor;
        $buyer = $po?->buyer;
        $payload = $efaktur->raw_request ?? [];
        $items = array_slice($payload['objekFaktur'] ?? [[
            'kdBrgJasa' => '000000', 'namaBrgJasa' => 'Barang/Jasa - ' . ($efaktur->no_invoice ?: 'PO'),
            'jmlBrgJasa' => '1', 'satuanBrgJasa' => 'Unit', 'hargaSatuan' => (string) $efaktur->dpp,
            'totalHarga' => (string) $efaktur->dpp,
        ]], 0, 3);
        $vendorName = $vendor?->name ?: ($po?->vendor_name ?: 'Pengusaha Kena Pajak');
        $buyerName = $buyer?->name ?: 'Pembeli Barang Kena Pajak';
        $vendorAddress = trim(implode(', ', array_filter([$vendor?->address, $vendor?->city, $vendor?->region, $vendor?->zip_code])));
        $buyerAddress = trim(implode(', ', array_filter([$buyer?->address, $buyer?->city, $buyer?->region, $buyer?->zip_code])));
        $vendorTaxId = preg_replace('/\D/', '', (string) ($vendor?->tax_id ?? $efaktur->npwp_penjual ?? ''));
        $buyerTaxId = preg_replace('/\D/', '', (string) ($buyer?->tax_id ?? ''));

        $content = "q\n0.7 w\n";
        $content .= $this->rect(28, 24, 539, 794) . $this->line(28, 736, 567, 736);
        $content .= $this->text('Faktur Pajak', 297, 812, 16, true, 'center');
        $content .= $this->text('Nama: ' . $vendorName, 330, 788, 9, false)
            . $this->multiline($this->wrap('Alamat: ' . ($vendorAddress ?: '-'), 51), 330, 775, 9, 11)
            . $this->text('#' . ($vendorTaxId ?: '0000000000000000') . '000000', 330, 743, 9);
        $content .= $this->section(28, 708, 539, 28, 'Kode dan Nomor Seri Faktur Pajak: ' . ($efaktur->nofa ?: '-'));
        $content .= $this->section(28, 682, 539, 26, 'Pengusaha Kena Pajak:');
        $content .= $this->rect(28, 610, 539, 72)
            . $this->multiline(['Nama : ' . $vendorName, 'Alamat : ' . ($vendorAddress ?: '-'), 'NPWP : ' . ($vendorTaxId ?: '-')], 38, 662, 9, 12);
        $content .= $this->section(28, 584, 539, 26, 'Pembeli Barang Kena Pajak/Penerima Jasa Kena Pajak:');
        $content .= $this->rect(28, 498, 539, 86)
            . $this->multiline(['Nama : ' . $buyerName, 'Alamat : ' . ($buyerAddress ?: '-'), 'NPWP : ' . ($buyerTaxId ?: '-'), 'NIK : -', 'Nomor Paspor : -', 'Email : ' . ($buyer?->email ?: '-')], 38, 564, 9, 11);

        $tableTop = 498;
        $headerBottom = 453;
        $content .= $this->rect(28, $headerBottom, 539, $tableTop - $headerBottom)
            . $this->line(68, $headerBottom, 68, $tableTop) . $this->line(125, $headerBottom, 125, $tableTop) . $this->line(420, $headerBottom, 420, $tableTop)
            . $this->text('No.', 48, 474, 8, false, 'center') . $this->multiline(['Kode', 'Barang/', 'Jasa'], 96, 485, 8, 9, true, 'center')
            . $this->text('Nama Barang Kena Pajak / Jasa Kena Pajak', 272, 474, 8, false, 'center')
            . $this->multiline(['Harga Jual / Penggantian /', 'Uang Muka / Termin', '(Rp)'], 493, 483, 8, 9, true, 'center');
        $rowTop = $headerBottom;
        foreach ($items as $index => $item) {
            $rowHeight = 42;
            $rowBottom = $rowTop - $rowHeight;
            $description = $this->wrap((string) ($item['namaBrgJasa'] ?? 'Barang'), 43);
            $description[] = sprintf('Rp %s x %s %s', number_format((float) ($item['hargaSatuan'] ?? 0), 0, ',', '.'), $item['jmlBrgJasa'] ?? 1, $item['satuanBrgJasa'] ?? 'Unit');
            $content .= $this->rect(28, $rowBottom, 539, $rowHeight) . $this->line(68, $rowBottom, 68, $rowTop) . $this->line(125, $rowBottom, 125, $rowTop) . $this->line(420, $rowBottom, 420, $rowTop)
                . $this->text((string) ($index + 1), 48, $rowBottom + 20, 8, false, 'center') . $this->text((string) ($item['kdBrgJasa'] ?? '000000'), 96, $rowBottom + 20, 8, false, 'center')
                . $this->multiline(array_slice($description, 0, 3), 134, $rowTop - 12, 8, 9) . $this->text(number_format((float) ($item['totalHarga'] ?? 0), 0, ',', '.'), 556, $rowBottom + 20, 8, false, 'right');
            $rowTop = $rowBottom;
        }
        $totals = ['Harga Jual / Penggantian / Uang Muka / Termin' => (float) $efaktur->dpp, 'Dikurangi Potongan Harga' => 0, 'Dikurangi Uang Muka yang telah diterima' => 0, 'Dasar Pengenaan Pajak' => (float) $efaktur->dpp, 'Jumlah PPN (Pajak Pertambahan Nilai)' => (float) $efaktur->ppn, 'Jumlah PPnBM (Pajak Penjualan atas Barang Mewah)' => 0];
        foreach ($totals as $label => $amount) {
            $next = $rowTop - 24;
            $content .= $this->rect(28, $next, 539, 24) . $this->line(420, $next, 420, $rowTop) . $this->text($label, 38, $next + 8, 8) . $this->text(number_format($amount, 0, ',', '.'), 556, $next + 8, 8, false, 'right');
            $rowTop = $next;
        }
        $footerY = max(65, $rowTop - 15);
        $content .= $this->multiline(['Sesuai dengan ketentuan yang berlaku, Faktur Pajak ini dibuat dalam simulasi lokal.', 'Dokumen ini bukan dokumen pajak resmi dan tidak berlaku untuk pelaporan DJP.'], 38, $footerY, 7, 9)
            . $this->fakeQr(52, $footerY - 80, $this->approvalSign($efaktur), 62) . $this->text('SIMULASI LOKAL', 85, $footerY - 92, 7, false, 'center')
            . $this->text(($vendor?->city ?: 'Jakarta') . ', ' . now()->translatedFormat('d F Y'), 340, $footerY - 16, 9) . $this->fakeQr(360, $footerY - 80, $efaktur->id, 62)
            . $this->text('Ditandatangani secara elektronik', 340, $footerY - 92, 7) . $this->text($vendorName, 340, $footerY - 106, 9, true)
            . $this->text('Referensi: ' . ($efaktur->no_invoice ?: '-'), 38, 42, 6) . $this->text('1 dari 1', 556, 42, 6, false, 'right') . "Q\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ' . "\n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function section(float $x, float $top, float $width, float $height, string $label): string
    {
        return $this->rect($x, $top - $height, $width, $height)
            . $this->text($label, $x + 10, $top - 18, 9);
    }

    private function rect(float $x, float $y, float $width, float $height): string
    {
        return sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $width, $height);
    }

    private function line(float $fromX, float $fromY, float $toX, float $toY): string
    {
        return sprintf("%.2F %.2F m %.2F %.2F l S\n", $fromX, $fromY, $toX, $toY);
    }

    private function text(string $value, float $x, float $y, int $size, bool $bold = false, string $align = 'left'): string
    {
        $value = $this->escapePdfText($value);
        $width = strlen($value) * $size * 0.49;
        if ($align === 'center') {
            $x -= $width / 2;
        } elseif ($align === 'right') {
            $x -= $width;
        }

        return sprintf("BT /F%d %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $bold ? 2 : 1, $size, $x, $y, $value);
    }

    private function multiline(array $lines, float $x, float $y, int $size, int $leading, bool $bold = false, string $align = 'left'): string
    {
        $content = '';
        foreach ($lines as $index => $line) {
            $content .= $this->text((string) $line, $x, $y - ($index * $leading), $size, $bold, $align);
        }

        return $content;
    }

    private function wrap(string $value, int $characters): array
    {
        $words = preg_split('/\s+/', trim($value)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = trim($line . ' ' . $word);
            if ($line !== '' && strlen($candidate) > $characters) {
                $lines[] = $line;
                $line = $word;
                continue;
            }
            $line = $candidate;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines ?: ['-'];
    }

    private function fakeQr(float $x, float $y, string $payload, float $size): string
    {
        $modules = 21;
        $cell = $size / $modules;
        $bits = str_split(implode('', array_map(
            static fn (string $byte) => str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT),
            str_split(hash('sha256', $payload, true)),
        )));
        $content = '';
        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                $finder = $this->finderModule($row, $column, 0, 0)
                    || $this->finderModule($row, $column, 0, $modules - 7)
                    || $this->finderModule($row, $column, $modules - 7, 0);
                $bitIndex = ($row * $modules + $column) % count($bits);
                if ($finder || $bits[$bitIndex] === '1') {
                    $content .= sprintf("%.2F %.2F %.2F %.2F re f\n", $x + ($column * $cell), $y + (($modules - $row - 1) * $cell), $cell, $cell);
                }
            }
        }

        return $content;
    }

    private function finderModule(int $row, int $column, int $top, int $left): bool
    {
        if ($row < $top || $row >= $top + 7 || $column < $left || $column >= $left + 7) {
            return false;
        }
        $relativeRow = $row - $top;
        $relativeColumn = $column - $left;

        return $relativeRow === 0 || $relativeRow === 6 || $relativeColumn === 0 || $relativeColumn === 6
            || ($relativeRow >= 2 && $relativeRow <= 4 && $relativeColumn >= 2 && $relativeColumn <= 4);
    }

    private function escapePdfText(string $value): string
    {
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
