<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Order - {{ $do->do_number }}</title>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #333; line-height: 1.6; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #f97316; padding-bottom: 20px; margin-bottom: 30px; align-items: center; }
        .logo-section { display: flex; align-items: center; gap: 20px; }
        .company-logo { max-height: 60px; max-width: 150px; object-fit: contain; }
        .huntr-logo { font-size: 28px; font-weight: 900; color: #f97316; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 24px; color: #333; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .section-title { font-size: 12px; text-transform: uppercase; color: #666; font-weight: 700; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { background: #f8f9fa; text-align: left; padding: 12px; font-size: 13px; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .footer { margin-top: 50px; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 20px; text-align: center; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; margin-top: 60px; text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 80px; width: 60%; margin-left: auto; margin-right: auto; padding-top: 10px; }
        .powered-by { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 15px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <div class="logo-section">
            @if($po['buyer_logo_url'])
                <img src="{{ $po['buyer_logo_url'] }}" alt="Buyer Logo" class="company-logo">
            @endif
            @if($po['vendor_logo_url'])
                <img src="{{ $po['vendor_logo_url'] }}" alt="Vendor Logo" class="company-logo">
            @endif
            <div class="huntr-logo">HUNTR.ID</div>
        </div>
        <div class="title">
            <h1>DELIVERY ORDER</h1>
            <p>#{{ $do->do_number }}</p>
        </div>
    </div>

    <div class="info-grid">
        <div>
            <div class="section-title">Delivery To</div>
            <strong>{{ $po['buyer_name'] }}</strong><br>
            {{ $po['buyer_address'] }}<br>
            {{ $po['department'] }} Department
        </div>
        <div style="text-align: right;">
            <div class="section-title">Vendor / Shipper</div>
            <strong>{{ $po['vendor_name'] }}</strong><br>
            Status: {{ ucfirst($do->status) }}<br>
            Reference PO: {{ $po['po_number'] }}<br>
            @if($do->tracking_number)
            Tracking Number / Resi: {{ $do->tracking_number }}
            @endif
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
            @php
                $insp = $inspections[$item['id']] ?? null;
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['inventory_name'] }}</td>
                <td>{{ $item['inventory_code'] }}</td>
                <td style="text-align: center;">{{ $item['qty'] }} {{ $item['uom'] }}</td>
                @if($receipt)
                <td style="text-align: center; font-weight: bold; color: #22c55e;">{{ $insp ? $insp['received_qty'] : '-' }}</td>
                <td style="text-align: center; font-weight: bold; color: #f87171;">{{ $insp ? $insp['rejected_qty'] : '-' }}</td>
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

    <div class="footer">
        <p>&copy; {{ date('Y') }} Huntr.id Procurement System</p>
        <div class="powered-by">
            <span>Powered by</span>
            <span style="font-weight: 900; color: #f97316; font-size: 18px;">HUNTR.ID</span>
        </div>
    </div>
</body>
</html>
