<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>BAST - {{ $bast->bast_number }}</title>
    @include('print._styles', ['accentColor' => '#f97316'])
    <style>
        .signature-section { margin-top: 40px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .signature-box { border-top: 1px solid #999; padding-top: 16px; text-align: center; }
        .signature-box .name { font-weight: 700; margin-top: 36px; font-size: 13px; }
        .signature-box .position { font-size: 11px; color: #666; margin-top: 4px; }
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
        </tbody>
    </table>
    @else
    <p style="color: #999; font-size: 13px;">No items recorded in this BAST.</p>
    @endif

    <div class="signature-section">
        <div class="signature-box">
            <div style="font-size: 12px; font-weight: 700;">Handed By</div>
            @if($bast->handed_by_signed_at)
            <div style="margin: 24px 0 8px; font-size: 11px; color: #666;">Signed: {{ $bast->handed_by_signed_at->format('d/m/Y H:i') }}</div>
            @endif
            <div class="name">{{ $bast->handed_by_name }}</div>
            <div class="position">{{ $bast->handed_by_position }}</div>
        </div>
        <div class="signature-box">
            <div style="font-size: 12px; font-weight: 700;">Received By</div>
            @if($bast->received_by_signed_at)
            <div style="margin: 24px 0 8px; font-size: 11px; color: #666;">Signed: {{ $bast->received_by_signed_at->format('d/m/Y H:i') }}</div>
            @endif
            <div class="name">{{ $bast->received_by_name }}</div>
            <div class="position">{{ $bast->received_by_position }}</div>
        </div>
    </div>

    @include('print._footer', ['footerNote' => 'This is a computer-generated BAST document from Huntr.id Procurement System.'])
</div>
</body>
</html>
