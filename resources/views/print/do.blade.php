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

    </div>

    {{-- Delivery Point --}}
    @if(!empty($po['delivery_point']) || !empty($do->delivery_address))
    <div style="margin-bottom: 24px; padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; display: flex; align-items: flex-start; gap: 10px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#f97316" viewBox="0 0 16 16" style="margin-top: 2px; flex-shrink: 0;">
            <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
        </svg>
        <div>
            <div class="section-title" style="margin-bottom: 2px;">Delivery Point / Titik Pengiriman</div>
            <strong style="font-size: 13px;">{{ $po['delivery_point'] ?: $do->delivery_address }}</strong>
        </div>
    </div>
    @endif

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
        $doBaseAmt      = $po['total_amount'];
        $doPlatFee      = $doBaseAmt * 0.03;              // Platform fee 3%
        $doAdminBank    = 4400;                          // Admin Bank flat
        $doPpnEcomm     = ($doPlatFee + $doAdminBank) * 0.08; // PPN eComm 8%
        $doBiayaLayanan = $doPlatFee + $doAdminBank + $doPpnEcomm; // Total biaya layanan
        $doPpn          = $doBaseAmt * 0.11;              // PPN 11% dari DPP
        $doTotal        = $doBaseAmt + $doBiayaLayanan + $doPpn;
    @endphp
    <div class="section-title" style="margin-top: 24px;">Ringkasan Biaya / Financial Summary</div>
    <table style="margin-bottom: 16px;">
        <tbody>
            <tr style="background: #f9fafb; font-weight: 600;">
                <td colspan="3" style="text-align: right;">Total Pembelian sebelum PPN</td>
                <td style="text-align: right;">{{ number_format($doBaseAmt) }}</td>
            </tr>
            {{-- Platform fee --}}
            <tr style="background: #f9fafb;">
                <td colspan="2" style="text-align: right; color: #6b7280; font-size: 12px;">platform fee</td>
                <td style="text-align: right; color: #6b7280; font-size: 12px;">3%</td>
                <td style="text-align: right; color: #6b7280;">{{ number_format($doPlatFee) }}</td>
            </tr>
            {{-- Admin Bank --}}
            <tr style="background: #f9fafb;">
                <td colspan="2" style="text-align: right; color: #6b7280; font-size: 12px;">Admin Bank</td>
                <td style="text-align: right; color: #6b7280; font-size: 12px;"></td>
                <td style="text-align: right; color: #6b7280;">{{ number_format($doAdminBank) }}</td>
            </tr>
            {{-- PPN eComm --}}
            <tr style="background: #f9fafb;">
                <td colspan="2" style="text-align: right; color: #6b7280; font-size: 12px;">PPN eComm</td>
                <td style="text-align: right; color: #6b7280; font-size: 12px;">8%</td>
                <td style="text-align: right; color: #6b7280;">{{ number_format($doPpnEcomm) }}</td>
            </tr>
            {{-- Biaya Layanan subtotal --}}
            <tr style="background: #f9fafb; font-weight: 600;">
                <td colspan="3" style="text-align: right;">Biaya Layanan <span style="font-size: 10px; font-weight: 400; color: #6b7280;">(Platform + Admin Bank + PPN eComm)</span></td>
                <td style="text-align: right;">{{ number_format($doBiayaLayanan) }}</td>
            </tr>
            {{-- PPN 11% --}}
            <tr style="background: #f9fafb; font-weight: 600;">
                <td colspan="2" style="text-align: right;">PPN</td>
                <td style="text-align: right;">11%</td>
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
