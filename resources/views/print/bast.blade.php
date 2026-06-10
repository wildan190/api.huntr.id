<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>BAST - {{ $bast->bast_number }}</title>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #333; line-height: 1.6; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #f97316; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 28px; font-weight: 900; color: #f97316; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 24px; color: #333; }
        .title p { margin: 5px 0 0; font-size: 14px; color: #666; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .section-title { font-size: 11px; text-transform: uppercase; color: #666; font-weight: 700; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .info-field { margin-bottom: 12px; }
        .info-field label { font-size: 11px; color: #999; text-transform: uppercase; }
        .info-field value { font-size: 14px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        th { background: #f8f9fa; text-align: left; padding: 12px; font-size: 12px; border-bottom: 2px solid #dee2e6; font-weight: 700; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        .signature-section { margin-top: 50px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; }
        .signature-box { border-top: 1px solid #999; padding-top: 20px; text-align: center; }
        .signature-box .name { font-weight: 700; margin-top: 40px; font-size: 13px; }
        .signature-box .position { font-size: 11px; color: #666; margin-top: 5px; }
        .footer { margin-top: 50px; font-size: 11px; color: #777; border-top: 1px solid #eee; padding-top: 20px; }
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
            <h1>BERITA ACARA SERAH TERIMA</h1>
            <p>(Handover Report - BAST)</p>
            <p>#{{ $bast->bast_number }}</p>
        </div>
    </div>

    <div class="info-grid">
        <div>
            <div class="section-title">Vendor (Penyerah)</div>
            <div class="info-field">
                <value>{{ $bast->vendorCompany?->name ?? 'N/A' }}</value>
            </div>
            <div class="info-field">
                <label>Issued By</label>
                <value>{{ $bast->handed_by_name }}</value>
            </div>
            <div class="info-field">
                <label>Position</label>
                <value>{{ $bast->handed_by_position }}</value>
            </div>
        </div>
        <div>
            <div class="section-title">Buyer (Penerima)</div>
            <div class="info-field">
                <value>{{ $bast->buyerCompany?->name ?? 'N/A' }}</value>
            </div>
            <div class="info-field">
                <label>Received By</label>
                <value>{{ $bast->received_by_name }}</value>
            </div>
            <div class="info-field">
                <label>Position</label>
                <value>{{ $bast->received_by_position }}</value>
            </div>
        </div>
    </div>

    <div class="info-grid" style="grid-template-columns: 1fr 1fr 1fr;">
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
                <value style="font-size: 12px;">{{ $bast->handover_notes }}</value>
            </div>
        </div>
    </div>

    <div style="margin-bottom: 30px;">
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
    </div>

    @if($bast->witness_name)
    <div style="margin-bottom: 30px;">
        <div class="section-title">Witness</div>
        <div class="info-field">
            <label>Name</label>
            <value>{{ $bast->witness_name }}</value>
        </div>
        <div class="info-field">
            <label>Position</label>
            <value>{{ $bast->witness_position }}</value>
        </div>
    </div>
    @endif

    <div class="signature-section">
        <div class="signature-box">
            <div style="text-align: center; font-size: 12px; font-weight: 700;">Handed By</div>
            @if($bast->handed_by_signed_at)
            <div style="margin: 40px 0; font-size: 11px; color: #666;">Signed at: {{ $bast->handed_by_signed_at->format('d/m/Y H:i') }}</div>
            @endif
            <div class="name">{{ $bast->handed_by_name }}</div>
            <div class="position">{{ $bast->handed_by_position }}</div>
        </div>
        <div class="signature-box">
            <div style="text-align: center; font-size: 12px; font-weight: 700;">Received By</div>
            @if($bast->received_by_signed_at)
            <div style="margin: 40px 0; font-size: 11px; color: #666;">Signed at: {{ $bast->received_by_signed_at->format('d/m/Y H:i') }}</div>
            @endif
            <div class="name">{{ $bast->received_by_name }}</div>
            <div class="position">{{ $bast->received_by_position }}</div>
        </div>
        <div class="signature-box">
            <div style="text-align: center; font-size: 12px; font-weight: 700;">Witness</div>
            @if($bast->witness_name)
                @if($bast->witness_signed_at)
                <div style="margin: 40px 0; font-size: 11px; color: #666;">Signed at: {{ $bast->witness_signed_at->format('d/m/Y H:i') }}</div>
                @endif
                <div class="name">{{ $bast->witness_name }}</div>
                <div class="position">{{ $bast->witness_position }}</div>
            @else
            <div style="margin: 40px 0; font-size: 11px; color: #999;"><em>Not signed</em></div>
            @endif
        </div>
    </div>

    <div class="footer">
        <p>This is a computer-generated BAST document from Huntr.id Procurement System.</p>
        <p>&copy; {{ date('Y') }} Huntr.id - All rights reserved</p>
    </div>
</body>
</html>
