<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>BAST - {{ $bast->bast_number }}</title>
    @include('print._styles', ['accentColor' => '#f97316'])
    <style>
        .info-field { margin-bottom: 10px; }
        .info-field label { font-size: 10px; color: #999; text-transform: uppercase; display: block; }
        .info-field value { font-size: 13px; font-weight: 600; }
    </style>
</head>
<body onload="window.print()">
<div class="print-doc">
    @include('print._header', [
        'docTitle' => 'BERITA ACARA SERAH TERIMA',
        'docSubtitle' => 'Handover Report (BAST)',
        'docNumber' => $bast->bast_number,
        'buyerLabel' => 'Buyer / Penerima',
        'vendorLabel' => 'Vendor / Penyerah',
        'buyerName' => $bast->buyerCompany?->name ?? 'N/A',
        'buyerAddress' => $bast->buyerCompany?->address ?? null,
        'buyerLogoUrl' => $buyer_logo_url ?? null,
        'buyerTaxId' => $bast->buyerCompany?->formatted_tax_id ?? null,
        'vendorName' => $bast->vendorCompany?->name ?? 'N/A',
        'vendorAddress' => $bast->vendorCompany?->address ?? null,
        'vendorLogoUrl' => $vendor_logo_url ?? null,
        'vendorTaxId' => $bast->vendorCompany?->formatted_tax_id ?? null,
        'accentColor' => '#f97316',
    ])

    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px;">
        <div>
            <div class="section-title">Document Info</div>
            <div class="info-field">
                <label>BAST Date</label>
                <value>{{ $bast->bast_date?->format('d/m/Y') }}</value>
            </div>
            <div class="info-field">
                <label>Status</label>
                <value style="text-transform: capitalize;">{{ $bast->status }}</value>
            </div>
        </div>
        <div>
            <div class="section-title">Related Purchase Order</div>
            <div class="info-field">
                <label>PO Number</label>
                <value>{{ $bast->purchaseOrder?->po_number ?? 'N/A' }}</value>
            </div>
        </div>
        <div>
            <div class="section-title">Handover Notes</div>
            <div class="info-field">
                <value style="font-size: 12px;">{{ $bast->handover_notes ?: '—' }}</value>
            </div>
        </div>
    </div>

    <div class="section-title">Representatives</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
        <div class="party-card" style="background: #fff;">
            <div class="party-label">Vendor Representative (Penyerah)</div>
            <p class="party-name" style="font-size: 14px;">{{ $bast->handed_by_name }}</p>
            <p class="party-detail">{{ $bast->handed_by_position }}</p>
        </div>
        <div class="party-card" style="background: #fff;">
            <div class="party-label">Buyer Representative (Penerima)</div>
            <p class="party-name" style="font-size: 14px;">{{ $bast->received_by_name }}</p>
            <p class="party-detail">{{ $bast->received_by_position }}</p>
        </div>
    </div>

    <div class="section-title">Items Handed Over</div>
    @if($bast->items && count($bast->items) > 0)
    <table>
        <thead>
            <tr>
                <th>Item Description</th>
                <th>Code</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bast->items as $item)
            <tr>
                <td>{{ $item['inventory_name'] ?? 'N/A' }}</td>
                <td>{{ $item['inventory_code'] ?? 'N/A' }}</td>
                <td style="text-align: center;">{{ $item['qty'] ?? 0 }} {{ $item['uom'] ?? 'Unit' }}</td>
                <td style="text-align: right;">{{ number_format($item['unit_price'] ?? 0) }}</td>
                <td style="text-align: right;">{{ number_format($item['total_amount'] ?? 0) }}</td>
            </tr>
            @endforeach
            @php
                $bastBase = collect($bast->items)->sum(fn($i) => $i['total_amount'] ?? 0);
                if ($bastBase <= 100000000) {
                    $bastPlatFeeRate = 0.05;
                } elseif ($bastBase <= 250000000) {
                    $bastPlatFeeRate = 0.03;
                } else {
                    $bastPlatFeeRate = 0.02;
                }
                $bastPlatFee      = $bastBase * $bastPlatFeeRate;
                $bastPpnPlatform  = $bastPlatFee * 0.11;
                $bastAdminBank    = 4400;
                $bastPph23        = $bastPlatFee * 0.02;
                $bastBiayaLayanan = ($bastPlatFee + $bastPpnPlatform) + $bastAdminBank - $bastPph23;
                $bastPpn          = $bastBase * 0.11;
                $bastTotal        = $bastBase + $bastBiayaLayanan + $bastPpn;
            @endphp
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">Total Pembelian Barang sebelum PPN</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($bastBase) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="3" style="text-align: right; color: #78350f;">Platform Fee + PPN</td>
                <td style="text-align: right; color: #78350f; font-size: 12px;">{{ number_format($bastPlatFeeRate * 100, 0) }}% + 11%</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($bastPlatFee + $bastPpnPlatform) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">Admin Bank</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($bastAdminBank) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="3" style="text-align: right; color: #78350f;">PPH 23</td>
                <td style="text-align: right; color: #78350f; font-size: 12px;">2%</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($bastPph23) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">Biaya Layanan <span style="font-size: 10px; font-weight: 400;">(Platform Fee + Admin Bank + PPH 23)</span></td>
                <td style="text-align: right; color: #78350f;">{{ number_format($bastBiayaLayanan) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="3" style="text-align: right; color: #78350f;">PPN</td>
                <td style="text-align: right; color: #78350f; font-size: 12px;">11%</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($bastPpn) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="4" style="text-align: right;">TOTAL Amount (IDR)</td>
                <td style="text-align: right;">{{ number_format($bastTotal) }}</td>
            </tr>
        </tbody>
    </table>
    @else
    <p style="color: #999; font-size: 13px;">No items recorded in this BAST.</p>
    @endif

    <div class="section-title">Document Signatures</div>
    <div class="signature-section">
        @include('print._signature_block', [
            'docType' => 'bast',
            'docId' => $bast->id,
            'role' => 'handed-by',
            'label' => 'Handed By (Vendor)',
            'signerName' => $bast->handed_by_name,
            'signerPosition' => $bast->handed_by_position,
            'signedAt' => $bast->handed_by_signed_at?->toIso8601String(),
            'signedAtFormatted' => $bast->handed_by_signed_at?->format('d/m/Y H:i'),
        ])
        @include('print._signature_block', [
            'docType' => 'bast',
            'docId' => $bast->id,
            'role' => 'received-by',
            'label' => 'Received By (Buyer)',
            'signerName' => $bast->received_by_name,
            'signerPosition' => $bast->received_by_position,
            'signedAt' => $bast->received_by_signed_at?->toIso8601String(),
            'signedAtFormatted' => $bast->received_by_signed_at?->format('d/m/Y H:i'),
        ])
    </div>

    @include('print._footer', ['footerNote' => 'This is a computer-generated BAST document from Huntr.id Procurement System.'])
</div>
</body>
</html>
