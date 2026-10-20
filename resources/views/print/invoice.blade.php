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
                <th style="width: 40px; text-align: center;">No</th>
                <th>Description</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po['items'] as $index => $item)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>{{ $item['inventory_name'] }} ({{ $item['inventory_code'] }})</td>
                <td style="text-align: center;">{{ $item['qty'] }} {{ $item['uom'] }}</td>
                <td style="text-align: right;">{{ number_format($item['unit_price']) }}</td>
                <td style="text-align: right;">{{ number_format($item['total_amount']) }}</td>
            </tr>
            @endforeach
            @php
                $baseAmt = $paymentBreakdown['base_amount'];
                $adminBank = $paymentBreakdown['admin_bank'];
                $pph23 = $paymentBreakdown['pph23'];
                $ppn = $paymentBreakdown['ppn'];
                $totalAmount = $paymentBreakdown['total_amount'];
            @endphp
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">Total Pembelian Barang</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($baseAmt) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">Admin Bank</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($adminBank) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">PPh 23</td>
                <td style="text-align: right; color: #78350f;">- {{ number_format($pph23) }}</td>
            </tr>
            <tr style="background: #fffde7; font-weight: 700;">
                <td colspan="4" style="text-align: right; color: #78350f;">PPN</td>
                <td style="text-align: right; color: #78350f;">{{ number_format($ppn) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="4" style="text-align: right;">TOTAL Amount ({{ $po['currency'] }})</td>
                <td style="text-align: right;">{{ number_format($totalAmount) }}</td>
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
