<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Order - {{ $do->do_number }}</title>
    @include('print._styles', ['accentColor' => '#f97316'])
    <style>
        .signatures { display: grid; grid-template-columns: 1fr 1fr; margin-top: 40px; text-align: center; gap: 24px; }
    </style>
</head>
<body onload="window.print()">
<div class="print-doc">
    @include('print._header', [
        'docTitle' => 'DELIVERY ORDER',
        'docNumber' => $do->do_number,
        'buyerLabel' => 'Delivery To / Penerima',
        'vendorLabel' => 'Vendor / Shipper',
        'buyerName' => $po['buyer_name'],
        'buyerAddress' => $po['buyer_address'],
        'buyerLogoUrl' => $po['buyer_logo_url'] ?? null,
        'buyerTaxId' => $po['buyer_tax_id'] ?? null,
        'vendorName' => $po['vendor_name'],
        'vendorAddress' => $po['vendor_address'] ?? null,
        'vendorLogoUrl' => $po['vendor_logo_url'] ?? null,
        'vendorTaxId' => $po['vendor_tax_id'] ?? null,
        'accentColor' => '#f97316',
    ])

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
        <div>
            <div class="section-title">Status</div>
            <strong>{{ ucfirst($do->status) }}</strong>
        </div>
        <div>
            <div class="section-title">Reference PO</div>
            <strong>{{ $po['po_number'] }}</strong>
        </div>
        <div>
            <div class="section-title">Tracking / Resi</div>
            <strong>{{ $do->tracking_number ?: '—' }}</strong>
        </div>
    </div>

    @php
        $receipt = $do->goodsReceipts->first();
        $inspections = [];
        if ($receipt && $receipt->items_inspection) {
            $data = is_string($receipt->items_inspection) ? json_decode($receipt->items_inspection, true) : $receipt->items_inspection;
            if (is_array($data)) {
                foreach($data as $insp) {
                    $inspections[$insp['po_item_id']] = $insp;
                }
            }
        }
    @endphp

    <div class="section-title">Delivered Items</div>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Item Description</th>
                <th>Code</th>
                <th style="text-align: center;">Ordered Qty</th>
                @if($receipt)
                <th style="text-align: center;">Accepted</th>
                <th style="text-align: center;">Rejected</th>
                <th>Condition</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($po['items'] as $index => $item)
            @php $insp = $inspections[$item['id']] ?? null; @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['inventory_name'] }}</td>
                <td>{{ $item['inventory_code'] }}</td>
                <td style="text-align: center;">{{ $item['qty'] }} {{ $item['uom'] }}</td>
                @if($receipt)
                <td style="text-align: center; font-weight: bold; color: #16a34a;">{{ $insp ? $insp['received_qty'] : '-' }}</td>
                <td style="text-align: center; font-weight: bold; color: #dc2626;">{{ $insp ? $insp['rejected_qty'] : '-' }}</td>
                <td>{{ $insp && $insp['condition'] ? $insp['condition'] : '-' }}</td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Financial Summary --}}
    @php
        $doBaseAmt = $po['total_amount'];
        if ($doBaseAmt <= 50000000) {
            $doPlatFee = $doBaseAmt * 0.025;
        } elseif ($doBaseAmt <= 250000000) {
            $doPlatFee = $doBaseAmt * 0.02;
        } else {
            $doPlatFee = $doBaseAmt * 0.01;
        }
        $doAdminFee = 4400;
        $doServiceFee = $doPlatFee + $doAdminFee;
        $doPpn = $doServiceFee * 0.11;
        $doTotal = $doBaseAmt + $doServiceFee + $doPpn;
    @endphp
    <div class="section-title" style="margin-top: 24px;">Ringkasan Biaya / Financial Summary</div>
    <table style="margin-bottom: 16px;">
        <tbody>
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right;">Nilai Barang (DPP)</td>
                <td style="text-align: right;">{{ number_format($doBaseAmt) }}</td>
            </tr>
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right;">BIAYA LAYANAN <span style="font-size: 10px; color: #6b7280;">(Platform + Admin Pembayaran)</span></td>
                <td style="text-align: right;">{{ number_format($doServiceFee) }}</td>
            </tr>
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right;">PPN 11% <span style="font-size: 10px; color: #6b7280;">(atas biaya layanan)</span></td>
                <td style="text-align: right;">{{ number_format($doPpn) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="3" style="text-align: right;">TOTAL PAYABLE ({{ $po['currency'] }})</td>
                <td style="text-align: right;">{{ number_format($doTotal) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Document Signatures</div>
    <div class="signature-section">
        @include('print._signature_block', [
            'docType' => 'do',
            'docId' => $do->id,
            'role' => 'received-by',
            'label' => 'Received By (Buyer)',
            'signerName' => $do->received_by_name,
            'signerPosition' => $do->received_by_position,
            'signedAt' => $do->received_by_signed_at?->toIso8601String(),
            'signedAtFormatted' => $do->received_by_signed_at?->format('d/m/Y H:i'),
        ])
        @include('print._signature_block', [
            'docType' => 'do',
            'docId' => $do->id,
            'role' => 'handed-by',
            'label' => 'Delivered By (Vendor)',
            'signerName' => $do->handed_by_name,
            'signerPosition' => $do->handed_by_position,
            'signedAt' => $do->handed_by_signed_at?->toIso8601String(),
            'signedAtFormatted' => $do->handed_by_signed_at?->format('d/m/Y H:i'),
        ])
    </div>

    @include('print._footer')
</div>
</body>
</html>
