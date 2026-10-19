<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Pengadaan Baru di HUNTR</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-bottom: 3px solid #10b981;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 700; letter-spacing: 1px;">HUNTR</h1>
                            <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px;">Peluang Pengadaan Baru (RFQ)</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            <div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                <span style="color: #059669; font-weight: 600; font-size: 14px;">🎯 Cocok Dengan Produk/Kategori Anda</span>
                            </div>

                            <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                Halo, {{ $company->name }}!
                            </h2>
                            <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                Terdapat permintaan pengadaan baru (RFQ) yang relevan dengan spesifikasi dan katalog produk perusahaan Anda. Jangan lewatkan kesempatan untuk mengirimkan penawaran terbaik!
                            </p>

                            <!-- RFQ Overview Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; width: 140px;">Judul Request</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 700;">{{ $rfq->title }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Perusahaan Buyer</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $buyerName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Batas Waktu / Durasi</td>
                                                <td style="padding: 6px 0; color: #2563eb; font-size: 14px; font-weight: 600;">
                                                    {{ $rfq->duration_days ? $rfq->duration_days . ' Hari' : '-' }}
                                                </td>
                                            </tr>
                                            @if($rfq->delivery_point)
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Lokasi Pengiriman</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px;">{{ $rfq->delivery_point }}</td>
                                            </tr>
                                            @endif
                                            @if($rfq->description)
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; vertical-align: top;">Deskripsi</td>
                                                <td style="padding: 6px 0; color: #475569; font-size: 13px; line-height: 1.5;">{{ Str::limit($rfq->description, 160) }}</td>
                                            </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Items Summary Table (if items exist) -->
                            @if(isset($items) && count($items) > 0)
                            <h3 style="margin: 0 0 10px 0; color: #0f172a; font-size: 15px; font-weight: 600;">Rincian Barang yang Diminta:</h3>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; margin-bottom: 25px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                                <tr style="background-color: #f1f5f9;">
                                    <th style="padding: 8px 12px; text-align: left; font-size: 12px; color: #475569; border-bottom: 1px solid #e2e8f0;">Item</th>
                                    <th style="padding: 8px 12px; text-align: right; font-size: 12px; color: #475569; border-bottom: 1px solid #e2e8f0;">Qty</th>
                                    <th style="padding: 8px 12px; text-align: left; font-size: 12px; color: #475569; border-bottom: 1px solid #e2e8f0;">Satuan</th>
                                </tr>
                                @foreach($items->take(5) as $item)
                                <tr>
                                    <td style="padding: 8px 12px; font-size: 13px; color: #1e293b; border-bottom: 1px solid #f1f5f9;">{{ $item->item_name }}</td>
                                    <td style="padding: 8px 12px; font-size: 13px; color: #1e293b; text-align: right; font-weight: 600; border-bottom: 1px solid #f1f5f9;">{{ number_format($item->quantity ?? 1) }}</td>
                                    <td style="padding: 8px 12px; font-size: 13px; color: #64748b; border-bottom: 1px solid #f1f5f9;">{{ $item->uom ?? 'Unit' }}</td>
                                </tr>
                                @endforeach
                                @if(count($items) > 5)
                                <tr>
                                    <td colspan="3" style="padding: 6px 12px; font-size: 12px; color: #64748b; background-color: #f8fafc; text-align: center;">
                                        + {{ count($items) - 5 }} item lainnya
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- CTA Button -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="{{ $rfqUrl }}" style="display: inline-block; background-color: #10b981; color: #ffffff; text-decoration: none; padding: 13px 30px; border-radius: 6px; font-weight: 700; font-size: 15px; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.25);">
                                    Lihat Detail & Ajukan Penawaran
                                </a>
                            </div>

                            <p style="margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5; text-align: center;">
                                Notifikasi ini dikirim secara otomatis oleh algoritma pencocokan cerdas HUNTR berdasarkan profil dan portofolio produk perusahaan Anda.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center;">
                            <p style="margin: 0 0 6px 0; color: #64748b; font-size: 12px;">
                                &copy; {{ date('Y') }} HUNTR (PT Huntr Teknologi Indonesia). All rights reserved.
                            </p>
                            <p style="margin: 0; color: #94a3b8; font-size: 12px;">
                                Pertanyaan atau bantuan penawaran? Hubungi kami di <a href="mailto:support@huntr.id" style="color: #2563eb; text-decoration: none;">support@huntr.id</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
