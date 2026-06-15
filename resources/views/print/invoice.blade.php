<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->status === 'paid' ? 'Tax Invoice' : 'Proforma Invoice' }} - {{ $po['po_number'] }}</title>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #333; line-height: 1.6; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #f59e0b; padding-bottom: 20px; margin-bottom: 30px; align-items: center; }
        .logo-section { display: flex; align-items: center; gap: 20px; }
        .company-logo { max-height: 60px; max-width: 150px; object-fit: contain; }
        .huntr-logo { font-size: 28px; font-weight: 900; color: #f59e0b; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 24px; color: #333; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .section-title { font-size: 12px; text-transform: uppercase; color: #666; font-weight: 700; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { background: #f8f9fa; text-align: left; padding: 12px; font-size: 13px; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .total-row { font-weight: 900; font-size: 16px; background: #fffaf0; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 6px; background: #fef3c7; color: #92400e; font-weight: 700; font-size: 12px; }
        .footer { margin-top: 50px; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 20px; text-align: center; }
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
            <h1>{{ $invoice->status === 'paid' ? 'TAX INVOICE' : 'PROFORMA INVOICE' }}</h1>
            <p>Ref: {{ $po['po_number'] }}</p>
        </div>
    </div>

    <div class="info-grid">
        <div>
            <div class="section-title">Billed To</div>
            <strong>{{ $po['buyer_name'] }}</strong><br>
            {{ $po['buyer_address'] }}<br>
            Department: {{ $po['department'] }}
        </div>
        <div style="text-align: right;">
            <div class="section-title">Vendor</div>
            <strong>{{ $po['vendor_name'] }}</strong><br>
            Date: {{ date('Y-m-d') }}<br>
            Status: <span class="status-badge">{{ strtoupper($invoice->status) }}</span>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po['items'] as $item)
            <tr>
                <td>{{ $item['inventory_name'] }} ({{ $item['inventory_code'] }})</td>
                <td style="text-align: center;">{{ $item['qty'] }} {{ $item['uom'] }}</td>
                <td style="text-align: right;">{{ number_format($item['unit_price']) }}</td>
                <td style="text-align: right;">{{ number_format($item['total_amount']) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3" style="text-align: right;">TOTAL PAYABLE ({{ $po['currency'] }})</td>
                <td style="text-align: right;">{{ number_format($invoice->amount) }}</td>
            </tr>
        </tbody>
    </table>

    @if($invoice->status !== 'paid')
    <div style="margin-top: 20px; padding: 20px; background: #f9fafb; border-radius: 12px; font-size: 13px;">
        <h4 style="margin-top: 0;">Payment Instructions</h4>
        <p>Please complete the payment based on the total amount above. This proforma invoice is valid for 7 days.</p>
    </div>
    @else
    <div style="margin-top: 20px; padding: 20px; background: #ecfdf5; border-radius: 12px; font-size: 13px; color: #065f46; border: 1px solid #a7f3d0;">
        <h4 style="margin-top: 0;">Payment Confirmed</h4>
        <p>This invoice has been fully paid. Thank you for your business!</p>
    </div>
    @endif

    <div class="footer">
        <p>This is a computer-generated {{ $invoice->status === 'paid' ? 'tax' : 'proforma' }} invoice. No signature is required.</p>
        <p>&copy; {{ date('Y') }} Huntr.id Procurement System</p>
        <div class="powered-by">
            <span>Powered by</span>
            <span style="font-weight: 900; color: #f59e0b; font-size: 18px;">HUNTR.ID</span>
        </div>
    </div>
</body>
</html>
