<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Order - {{ $do->do_number }}</title>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #333; line-height: 1.6; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #f97316; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 28px; font-weight: 900; color: #f97316; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 24px; color: #333; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .section-title { font-size: 12px; text-transform: uppercase; color: #666; font-weight: 700; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { background: #f8f9fa; text-align: left; padding: 12px; font-size: 13px; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .footer { margin-top: 50px; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 20px; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; margin-top: 60px; text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 80px; width: 60%; margin-left: auto; margin-right: auto; padding-top: 10px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <div class="logo">HUNTR.ID</div>
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

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Item Description</th>
                <th>Code</th>
                <th style="text-align: center;">Ordered Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po['items'] as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['inventory_name'] }}</td>
                <td>{{ $item['inventory_code'] }}</td>
                <td style="text-align: center;">{{ $item['qty'] }} {{ $item['uom'] }}</td>
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
    </div>
</body>
</html>
