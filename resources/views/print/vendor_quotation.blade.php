<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Penawaran Harga - {{ $quotation['quotation_number'] }}</title>
    @include('print._styles', ['accentColor' => '#ef7d00'])
</head>
<body onload="window.print()">
<div class="print-doc">
    @include('print._header', [
        'docTitle' => 'SURAT PENAWARAN HARGA',
        'docSubtitle' => 'OFFICIAL VENDOR QUOTATION',
        'docNumber' => $quotation['quotation_number'],
        'buyerName' => $quotation['buyer_name'],
        'buyerAddress' => $quotation['buyer_address'] ?? null,
        'buyerLogoUrl' => $quotation['buyer_logo_url'] ?? null,
        'buyerTaxId' => $quotation['buyer_tax_id'] ?? null,
        'vendorName' => $quotation['vendor_name'],
        'vendorAddress' => $quotation['vendor_address'] ?? null,
        'vendorLogoUrl' => $quotation['vendor_logo_url'] ?? null,
        'vendorTaxId' => $quotation['vendor_tax_id'] ?? null,
        'buyerLabel' => 'Kepada Yth. (Calon Pembeli / Buyer)',
        'vendorLabel' => 'Pemasok / Vendor (Penerbit Penawaran)',
        'accentColor' => '#ef7d00',
    ])

    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; background: #fafafa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
        <div>
            <div class="section-title" style="margin-bottom: 2px;">Tanggal Penawaran</div>
            <strong>{{ $quotation['date'] }}</strong>
        </div>
        <div>
            <div class="section-title" style="margin-bottom: 2px;">Batas Waktu Pengiriman</div>
            <strong>{{ $quotation['delivery_days'] }} Hari Kerja</strong>
        </div>
        <div>
            <div class="section-title" style="margin-bottom: 2px;">Masa Garansi</div>
            <strong>{{ $quotation['warranty_months'] }} Bulan</strong>
        </div>
        <div>
            <div class="section-title" style="margin-bottom: 2px;">Skema Pembayaran</div>
            <strong>{{ $quotation['payment_term'] ?? 'Sesuai Kesepakatan' }}</strong>
        </div>
    </div>

    {{-- Reference RFQ --}}
    <div style="margin-bottom: 20px; padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px;">
        <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #c2410c; margin-bottom: 3px;">
            Referensi Tender / Request for Quotation (RFQ)
        </div>
        <div style="font-size: 13px; font-weight: 700; color: #1e293b;">
            {{ $quotation['rfq_title'] }}
        </div>
        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
            ID Tender: {{ $quotation['rfq_id'] }} &bull; Procurement Mode: {{ strtoupper($quotation['rfq_procurement_type'] ?? 'GOODS') }}
        </div>
    </div>

    <div class="section-title">Daftar Rincian Penawaran Barang / Jasa</div>
    <table>
        <thead>
            <tr>
                <th style="width: 36px; text-align: center;">No</th>
                <th>Deskripsi Barang / Jasa</th>
                <th style="width: 100px;">Kode Item</th>
                <th style="width: 80px; text-align: center;">Qty</th>
                <th style="width: 130px; text-align: right;">Harga Satuan</th>
                <th style="width: 140px; text-align: right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation['items'] as $index => $item)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>
                    <div style="font-weight: 600; color: #1e293b;">{{ $item['name'] }}</div>
                    @if(!empty($item['description']))
                        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">{{ $item['description'] }}</div>
                    @endif
                </td>
                <td style="font-size: 11px; color: #64748b;">{{ $item['code'] ?: '—' }}</td>
                <td style="text-align: center; font-weight: 600;">{{ $item['qty'] }} {{ $item['uom'] ?: 'unit' }}</td>
                <td style="text-align: right; font-family: monospace;">Rp {{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td style="text-align: right; font-weight: 700; font-family: monospace;">Rp {{ number_format($item['total_price'], 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background: #f8fafc; font-weight: 700;">
                <td colspan="5" style="text-align: right; padding: 10px 12px; font-size: 12px; text-transform: uppercase;">
                    Total Penawaran Harga (Sebelum PPN)
                </td>
                <td style="text-align: right; padding: 10px 12px; font-size: 13px; color: #ef7d00; font-family: monospace;">
                    Rp {{ number_format($quotation['total_amount'], 0, ',', '.') }}
                </td>
            </tr>
            @if(!empty($quotation['awarded_amount']) && $quotation['winner_status'] === 'awarded')
            <tr style="background: #f0fdf4; font-weight: 700;">
                <td colspan="5" style="text-align: right; padding: 10px 12px; font-size: 12px; color: #166534; text-transform: uppercase;">
                    Nilai yang Ditetapkan / Awarded Amount
                </td>
                <td style="text-align: right; padding: 10px 12px; font-size: 13px; color: #166534; font-family: monospace;">
                    Rp {{ number_format($quotation['awarded_amount'], 0, ',', '.') }}
                </td>
            </tr>
            @endif
        </tfoot>
    </table>

    {{-- Legal & Audit Trail Stamp --}}
    <div style="margin-top: 30px; display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
        <div style="padding: 14px 16px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 4px;">
                Catatan &amp; Ketentuan Penawaran (Terms &amp; Audit Trail)
            </div>
            <ul style="font-size: 11px; color: #64748b; line-height: 1.6; padding-left: 18px; margin: 0;">
                <li>Surat penawaran ini sah dan digenerate secara otomatis oleh sistem e-procurement Huntr.id saat vendor melakukan submisi bidding.</li>
                <li>Harga penawaran mencakup pengadaan sesuai spesifikasi teknis dan rincian kuantitas yang diajukan oleh buyer.</li>
                <li>Dokumen ini dapat digunakan sebagai arsip audit trail resmi untuk evaluasi kewajaran harga dan pembuktian kualifikasi vendor.</li>
            </ul>
        </div>

        <div style="text-align: center; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
            <div style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">
                Diterbitkan &amp; Diajukan Oleh:
            </div>
            <div style="display: inline-block; padding: 6px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; color: #047857; font-size: 11px; font-weight: 700; margin-bottom: 8px;">
                &#10003; VERIFIED DIGITAL BID
            </div>
            <div style="font-weight: 700; font-size: 13px; color: #1e293b;">
                {{ $quotation['vendor_name'] }}
            </div>
            <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">
                Submitted at: {{ $quotation['submitted_at'] }}
            </div>
        </div>
    </div>

    @include('print._footer', [
        'footerNote' => 'Dokumen resmi penawaran harga vendor yang tercatat pada sistem audit Huntr.id E-Procurement.',
    ])
</div>
</body>
</html>

