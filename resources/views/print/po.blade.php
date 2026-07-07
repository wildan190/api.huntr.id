<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - {{ $po['po_number'] }}</title>
    @include('print._styles', ['accentColor' => '#f97316'])
</head>
<body onload="window.print()">
<div class="print-doc">
    @include('print._header', [
        'docTitle' => 'PURCHASE ORDER',
        'docNumber' => $po['po_number'],
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
            <div class="section-title">Order Date</div>
            <strong>{{ $po['order_date'] }}</strong>
        </div>
        <div>
            <div class="section-title">Department</div>
            <strong>{{ $po['department'] }}</strong>
        </div>
        <div>
            <div class="section-title">Payment Scheme</div>
            <strong>{{ $po['purchase_type'] }}</strong>
        </div>
    </div>

    <div class="section-title">Order Items</div>
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
            @foreach($po['items'] as $item)
            <tr>
                <td>{{ $item['inventory_name'] }}</td>
                <td>{{ $item['inventory_code'] }}</td>
                <td style="text-align: center;">{{ $item['qty'] }} {{ $item['uom'] }}</td>
                <td style="text-align: right;">{{ number_format($item['unit_price']) }}</td>
                <td style="text-align: right;">{{ number_format($item['total_amount']) }}</td>
            </tr>
            @endforeach
            @php
                $baseAmt = $po['total_amount'];
                if ($baseAmt <= 50000000) {
                    $platFee = $baseAmt * 0.025;
                } elseif ($baseAmt <= 250000000) {
                    $platFee = $baseAmt * 0.02;
                } else {
                    $platFee = $baseAmt * 0.01;
                }
                $adminFee = 4400;
                $serviceFeeTotal = $platFee + $adminFee;
                $ppnOnFees = $serviceFeeTotal * 0.11;
                $estimatedTotal = $baseAmt + $serviceFeeTotal + $ppnOnFees;
            @endphp
            <tr style="background: #f9fafb;">
                <td colspan="4" style="text-align: right;">SUBTOTAL (DPP)</td>
                <td style="text-align: right;">{{ number_format($baseAmt) }}</td>
            </tr>
            <tr style="background: #f9fafb;">
                <td colspan="4" style="text-align: right;">BIAYA LAYANAN <span style="font-size: 10px; color: #6b7280;">(Platform + Admin Pembayaran)</span></td>
                <td style="text-align: right;">{{ number_format($serviceFeeTotal) }}</td>
            </tr>
            <tr style="background: #f9fafb;">
                <td colspan="4" style="text-align: right;">PPN 11% <span style="font-size: 10px; color: #6b7280;">(atas biaya layanan)</span></td>
                <td style="text-align: right;">{{ number_format($ppnOnFees) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="4" style="text-align: right;">ESTIMATED TOTAL PAYABLE ({{ $po['currency'] }})</td>
                <td style="text-align: right;">{{ number_format($estimatedTotal) }}</td>
            </tr>
        </tbody>
    </table>

    @include('print._footer', ['footerNote' => 'This is a computer-generated Purchase Order. No signature is required.'])
</div>
</body>
</html>
