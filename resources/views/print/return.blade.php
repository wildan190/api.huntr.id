<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Goods Return - {{ $return->return_number }}</title>
    @include('print._styles', ['accentColor' => '#f97316'])
    <style>
        .info-field { margin-bottom: 10px; }
        .info-field label { font-size: 10px; color: #999; text-transform: uppercase; display: block; margin-bottom: 2px; }
        .info-field value { font-size: 13px; font-weight: 600; color: #111; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-in_transit { background: #dbeafe; color: #2563eb; }
        .badge-received, .badge-processed { background: #dcfce7; color: #16a34a; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
    </style>
</head>
<body onload="window.print()">
<div class="print-doc">
    @include('print._header', [
        'docTitle' => 'NOTA RETUR BARANG',
        'docSubtitle' => 'Goods Return Note',
        'docNumber' => $return->return_number,
        'buyerLabel' => 'Buyer / Pengembali',
        'vendorLabel' => 'Vendor / Penerima Retur',
        'buyerName' => $return->buyerCompany?->name ?? 'N/A',
        'buyerAddress' => $return->buyerCompany?->address ?? null,
        'buyerLogoUrl' => $buyer_logo_url ?? null,
        'buyerTaxId' => $return->buyerCompany?->formatted_tax_id ?? null,
        'vendorName' => $return->vendorCompany?->name ?? 'N/A',
        'vendorAddress' => $return->vendorCompany?->address ?? null,
        'vendorLogoUrl' => $vendor_logo_url ?? null,
        'vendorTaxId' => $return->vendorCompany?->formatted_tax_id ?? null,
        'accentColor' => '#f97316',
    ])

    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px;">
        <div>
            <div class="section-title">Return Info</div>
            <div class="info-field">
                <label>Return Date</label>
                <value>{{ $return->return_date ? $return->return_date->format('d/m/Y') : ($return->created_at ? $return->created_at->format('d/m/Y') : '—') }}</value>
            </div>
            <div class="info-field">
                <label>Status</label>
                <value>
                    <span class="badge badge-{{ $return->status }}">{{ str_replace('_', ' ', $return->status) }}</span>
                </value>
            </div>
            <div class="info-field">
                <label>Reason</label>
                <value style="text-transform: capitalize;">{{ str_replace('_', ' ', $return->return_reason ?? 'Defective') }}</value>
            </div>
        </div>
        <div>
            <div class="section-title">Related Order Info</div>
            <div class="info-field">
                <label>PO Number</label>
                <value>{{ $return->purchaseOrder?->po_number ?? '—' }}</value>
            </div>
            @if($return->bast)
            <div class="info-field">
                <label>BAST Number</label>
                <value>{{ $return->bast->bast_number }}</value>
            </div>
            @endif
            @if($return->courier_name || $return->tracking_number)
            <div class="info-field">
                <label>Logistics</label>
                <value>{{ $return->courier_name ?? 'Courier' }} ({{ $return->tracking_number ?? 'No Resi' }})</value>
            </div>
            @endif
        </div>
        <div>
            <div class="section-title">Quality & Inspection</div>
            <div class="info-field">
                <label>Inspection Status</label>
                <value style="text-transform: capitalize;">{{ $return->inspection_status ?: 'Pending' }}</value>
            </div>
            @if($return->inspectedByUser)
            <div class="info-field">
                <label>Inspected By</label>
                <value>{{ $return->inspectedByUser->name }}</value>
            </div>
            @endif
            @if($return->return_description)
            <div class="info-field">
                <label>Notes / Description</label>
                <value style="font-size: 11px; font-weight: normal;">{{ $return->return_description }}</value>
            </div>
            @endif
        </div>
    </div>

    <div class="section-title">Returned Items</div>
    @if($return->items && count($return->items) > 0)
    <table>
        <thead>
            <tr>
                <th style="width: 40px; text-align: center;">No</th>
                <th>Item Description / Specification</th>
                <th style="text-align: center;">Qty Returned</th>
                <th style="text-align: right;">Unit Price (IDR)</th>
                <th style="text-align: right;">Total Value (IDR)</th>
            </tr>
        </thead>
        <tbody>
            @php $totalVal = 0; @endphp
            @foreach($return->items as $index => $item)
            @php
                $qty = (int)($item['quantity_returned'] ?? $item['qty'] ?? 1);
                $price = (float)($item['unit_price'] ?? 0);
                $subtotal = $qty * $price;
                $totalVal += $subtotal;
            @endphp
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>
                    <strong>{{ $item['name'] ?? $item['inventory_name'] ?? 'Item Ref #' . ($item['rfq_item_id'] ?? $index+1) }}</strong>
                    @if(!empty($item['reason']))
                        <div style="font-size: 11px; color: #666; margin-top: 2px;">Alasan: {{ $item['reason'] }}</div>
                    @endif
                </td>
                <td style="text-align: center; font-weight: 600;">{{ $qty }} {{ $item['uom'] ?? 'Unit' }}</td>
                <td style="text-align: right;">{{ number_format($price, 0, ',', '.') }}</td>
                <td style="text-align: right; font-weight: 600;">{{ number_format($subtotal, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background: #fafafa; font-weight: 700;">
                <td colspan="4" style="text-align: right; text-transform: uppercase; font-size: 11px; padding: 10px;">Total Nilai Retur</td>
                <td style="text-align: right; color: #f97316; font-size: 14px; padding: 10px;">IDR {{ number_format($return->total_return_value ?: $totalVal, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
    @else
    <p style="font-style: italic; color: #888; padding: 12px 0;">Tidak ada rincian item retur tercatat.</p>
    @endif

    <div style="margin-top: 40px; display: grid; grid-template-columns: 1fr 1fr; gap: 32px;">
        <div style="border-top: 1px solid #ddd; padding-top: 10px; text-align: center;">
            <p style="font-size: 11px; color: #888; text-transform: uppercase; margin-bottom: 50px;">Diserahkan oleh (Buyer)</p>
            <p style="font-weight: 700; font-size: 13px; margin: 0;">{{ $return->createdBy?->name ?? ($return->buyerCompany?->name ?? 'Pihak Pembeli') }}</p>
            <p style="font-size: 11px; color: #666; margin: 2px 0 0;">Authorized Signature</p>
        </div>
        <div style="border-top: 1px solid #ddd; padding-top: 10px; text-align: center;">
            <p style="font-size: 11px; color: #888; text-transform: uppercase; margin-bottom: 50px;">Diterima & Diperiksa oleh (Vendor)</p>
            <p style="font-weight: 700; font-size: 13px; margin: 0;">{{ $return->inspectedByUser?->name ?? ($return->vendorCompany?->name ?? 'Pihak Penjual') }}</p>
            <p style="font-size: 11px; color: #666; margin: 2px 0 0;">Authorized Signature</p>
        </div>
    </div>

    @include('print._footer')
</div>
</body>
</html>
