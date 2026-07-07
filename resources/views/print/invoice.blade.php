<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->status === 'paid' ? 'Tax Invoice' : 'Proforma Invoice' }} - {{ $po['po_number'] }}</title>
    @include('print._styles', ['accentColor' => '#f59e0b'])
    <style>
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 6px; background: #fef3c7; color: #92400e; font-weight: 700; font-size: 11px; }
    </style>
</head>
<body onload="window.print()">
<div class="print-doc">
    @php
        $isPaid = $invoice->status === 'paid';
        $docTitle = $isPaid ? 'TAX INVOICE' : 'PROFORMA INVOICE';
    @endphp

    @include('print._header', [
        'docTitle' => $docTitle,
        'docSubtitle' => strtoupper($invoice->type ?? 'invoice') . ' · Ref: ' . $po['po_number'],
        'docNumber' => $po['po_number'],
        'buyerLabel' => 'Billed To / Ditagihkan Kepada',
        'vendorLabel' => 'Vendor / Pemasok',
        'buyerName' => $po['buyer_name'],
        'buyerAddress' => $po['buyer_address'],
        'buyerLogoUrl' => $po['buyer_logo_url'] ?? null,
        'buyerTaxId' => $po['buyer_tax_id'] ?? null,
        'vendorName' => $po['vendor_name'],
        'vendorAddress' => $po['vendor_address'] ?? null,
        'vendorLogoUrl' => $po['vendor_logo_url'] ?? null,
        'vendorTaxId' => $po['vendor_tax_id'] ?? null,
        'accentColor' => '#f59e0b',
    ])

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
        <div>
            <div class="section-title">Invoice Date</div>
            <strong>{{ $invoice->created_at?->format('Y-m-d') ?? date('Y-m-d') }}</strong>
        </div>
        <div>
            <div class="section-title">Department</div>
            <strong>{{ $po['department'] }}</strong>
        </div>
        <div>
            <div class="section-title">Status</div>
            <span class="status-badge">{{ strtoupper($invoice->status) }}</span>
        </div>
    </div>

    <div class="section-title">Invoice Items</div>
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
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right;">SUBTOTAL (DPP)</td>
                <td style="text-align: right;">{{ number_format($invoice->base_amount ?? $invoice->amount) }}</td>
            </tr>
            @if(($invoice->platform_fee + $invoice->midtrans_fee) > 0)
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right;">BIAYA LAYANAN <span style="font-size: 10px; color: #6b7280;">(Platform + Admin Pembayaran)</span></td>
                <td style="text-align: right;">{{ number_format($invoice->platform_fee + $invoice->midtrans_fee) }}</td>
            </tr>
            @endif
            @if($invoice->ppn_fee > 0)
            <tr style="background: #f9fafb;">
                <td colspan="3" style="text-align: right;">PPN 11%</td>
                <td style="text-align: right;">{{ number_format($invoice->ppn_fee) }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td colspan="3" style="text-align: right;">TOTAL PAYABLE ({{ $po['currency'] }})</td>
                <td style="text-align: right;">{{ number_format($invoice->total_amount ?? $invoice->amount) }}</td>
            </tr>
        </tbody>
    </table>

    @if(!$isPaid)
    <div style="margin-top: 16px; padding: 16px; background: #f9fafb; border-radius: 12px; border: 1px solid #e5e7eb; font-size: 13px;">
        <h4 style="margin: 0 0 8px; font-size: 13px;">Payment Instructions</h4>
        <p style="margin: 0;">Please complete the payment based on the total amount above. This proforma invoice is valid for 7 days.</p>
    </div>
    @else
    <div style="margin-top: 16px; padding: 16px; background: #ecfdf5; border-radius: 12px; font-size: 13px; color: #065f46; border: 1px solid #a7f3d0;">
        <h4 style="margin: 0 0 8px; font-size: 13px;">Payment Confirmed</h4>
        <p style="margin: 0;">This invoice has been fully paid. Thank you for your business!</p>
    </div>
    @endif

    {{-- Vendor Signature Block --}}
    <div class="section-title" style="margin-top: 32px;">Document Signature</div>
    <div class="signature-section" style="display: flex; justify-content: flex-start;">
        @php
            $invoiceSignedAt = $invoice->vendor_signed_at?->toIso8601String();
            $invoiceSignedAtFormatted = $invoice->vendor_signed_at?->format('d/m/Y H:i');
        @endphp
        @include('print._signature_block', [
            'docType'           => 'invoice',
            'docId'             => $invoice->id,
            'role'              => 'vendor',
            'label'             => 'Issued & Authorized By (Vendor)',
            'signerName'        => $invoice->vendor_signed_name ?? ($po['vendor_name'] ?? null),
            'signerPosition'    => 'Vendor Representative',
            'signedAt'          => $invoiceSignedAt,
            'signedAtFormatted' => $invoiceSignedAtFormatted,
        ])
    </div>

    @include('print._footer', ['footerNote' => 'This is a computer-generated ' . ($isPaid ? 'tax' : 'proforma') . ' invoice. Scan QR code to verify authenticity.'])
</div>
</body>
</html>
