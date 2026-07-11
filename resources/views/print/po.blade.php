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

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
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

    {{-- Delivery Point --}}
    @if(!empty($po['delivery_point']))
    <div style="margin-bottom: 24px; padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; display: flex; align-items: flex-start; gap: 10px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#f97316" viewBox="0 0 16 16" style="margin-top: 2px; flex-shrink: 0;">
            <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
        </svg>
        <div>
            <div class="section-title" style="margin-bottom: 2px;">Delivery Point / Titik Pengiriman</div>
            <strong style="font-size: 13px;">{{ $po['delivery_point'] }}</strong>
        </div>
    </div>
    @endif

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
                $baseAmt     = $po['total_amount'];
                $platFee     = $baseAmt * 0.03;              // Platform fee 3%
                $adminBank   = 4400;                          // Admin Bank flat
                $ppnEcomm    = ($platFee + $adminBank) * 0.08; // PPN eComm 8%
                $biayaLayanan = $platFee + $adminBank + $ppnEcomm; // Total biaya layanan
                $ppn         = $baseAmt * 0.11;              // PPN 11% dari DPP
                $grandTotal  = $baseAmt + $biayaLayanan + $ppn;
            @endphp

            {{-- Total Pembelian sebelum PPN --}}
            <tr style="background: #f9fafb; font-weight: 600;">
                <td colspan="4" style="text-align: right;">Total Pembelian sebelum PPN</td>
                <td style="text-align: right;">{{ number_format($baseAmt) }}</td>
            </tr>

            {{-- Platform fee --}}
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right; color: #6b7280; font-size: 12px;">platform fee</td>
                <td style="text-align: right; color: #6b7280; font-size: 12px;">3%</td>
                <td style="text-align: right; color: #6b7280;">{{ number_format($platFee) }}</td>
            </tr>

            {{-- Admin Bank --}}
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right; color: #6b7280; font-size: 12px;">Admin Bank</td>
                <td style="text-align: right; color: #6b7280; font-size: 12px;"></td>
                <td style="text-align: right; color: #6b7280;">{{ number_format($adminBank) }}</td>
            </tr>

            {{-- PPN eComm --}}
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right; color: #6b7280; font-size: 12px;">PPN eComm</td>
                <td style="text-align: right; color: #6b7280; font-size: 12px;">8%</td>
                <td style="text-align: right; color: #6b7280;">{{ number_format($ppnEcomm) }}</td>
            </tr>

            {{-- Biaya Layanan subtotal --}}
            <tr style="background: #f9fafb; font-weight: 600;">
                <td colspan="4" style="text-align: right;">Biaya Layanan <span style="font-size: 10px; font-weight: 400; color: #6b7280;">(Platform + Admin Bank + PPN eComm)</span></td>
                <td style="text-align: right;">{{ number_format($biayaLayanan) }}</td>
            </tr>

            {{-- PPN 11% --}}
            <tr style="background: #f9fafb; font-weight: 600;">
                <td colspan="3" style="text-align: right;">PPN</td>
                <td style="text-align: right;">11%</td>
                <td style="text-align: right;">{{ number_format($ppn) }}</td>
            </tr>

            {{-- Grand Total --}}
            <tr class="total-row">
                <td colspan="4" style="text-align: right;">TOTAL Amount ({{ $po['currency'] }})</td>
                <td style="text-align: right;">{{ number_format($grandTotal) }}</td>
            </tr>
        </tbody>
    </table>

    @include('print._footer', ['footerNote' => 'This is a computer-generated Purchase Order. No signature is required.'])
</div>
</body>
</html>
