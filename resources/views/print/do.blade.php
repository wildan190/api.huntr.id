<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Order - {{ $do->do_number }}</title>
    @include('print._styles', ['accentColor' => '#f97316'])
    <style>
        .signatures { display: grid; grid-template-columns: 1fr 1fr; margin-top: 40px; text-align: center; gap: 24px; }
        .signature-line { border-top: 1px solid #333; margin-top: 70px; width: 70%; margin-left: auto; margin-right: auto; padding-top: 10px; font-size: 12px; }
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

    <div class="signatures">
        <div>
            <div><strong>Received By (Buyer)</strong></div>
            <div class="signature-line">Name / Date</div>
        </div>
        <div>
            <div><strong>Delivered By (Vendor)</strong></div>
            <div class="signature-line">Name / Date</div>
        </div>
    </div>

    @include('print._footer')
</div>
</body>
</html>
