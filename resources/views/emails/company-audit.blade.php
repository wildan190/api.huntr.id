<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemberitahuan Status Perusahaan</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-bottom: 3px solid #f97316;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 700; letter-spacing: 1px;">HUNTR</h1>
                            <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px;">Notifikasi Verifikasi Perusahaan</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            @if ($action === 'request_docs')
                                <div style="background-color: #fff7ed; border: 1px solid #ffedd5; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                    <span style="color: #ea580c; font-weight: 600; font-size: 14px;">📄 Permintaan Dokumen Legalitas Baru</span>
                                </div>

                                <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                    Lengkapi Dokumen Legalitas Perusahaan
                                </h2>
                                <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                    Tim Admin HUNTR meminta Anda untuk mengunggah atau memperbarui dokumen legalitas (seperti <strong>NPWP, NIB, atau SIUP</strong>) untuk perusahaan <strong>{{ $company->name }}</strong> agar dapat diproses ke status verifikasi <strong>Full Access</strong>.
                                </p>
                            @elseif ($action === 'approve')
                                <div style="background-color: #ecfdf5; border: 1px solid #d1fae5; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                    <span style="color: #059669; font-weight: 600; font-size: 14px;">✅ Verifikasi Perusahaan Berhasil</span>
                                </div>

                                <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                    Perusahaan Anda Telah Terverifikasi!
                                </h2>
                                <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                    Selamat! Dokumen dan profil perusahaan <strong>{{ $company->name }}</strong> telah disetujui oleh tim Admin HUNTR. Anda kini memiliki akses penuh untuk bertransaksi dan berpartisipasi dalam tender.
                                </p>
                            @else
                                <div style="background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                    <span style="color: #dc2626; font-weight: 600; font-size: 14px;">⚠️ Pembaruan Status Verifikasi</span>
                                </div>

                                <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                    Status Verifikasi Perusahaan
                                </h2>
                                <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                    Pengajuan verifikasi untuk perusahaan <strong>{{ $company->name }}</strong> belum dapat disetujui saat ini. Silakan periksa kembali berkas dan kelengkapan profil Anda.
                                </p>
                            @endif

                            <!-- Detail Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; width: 140px;">Nama Perusahaan</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 700;">{{ $company->name }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Peran Bisnis</td>
                                                <td style="padding: 6px 0; color: #ea580c; font-size: 14px; font-weight: 700;">{{ $companyType }}</td>
                                            </tr>
                                            @if (!empty($notes))
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; vertical-align: top;">Catatan Admin</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-style: italic; background-color: #fff; padding: 8px; border-radius: 6px; border: 1px dashed #cbd5e1;">
                                                    "{{ $notes }}"
                                                </td>
                                            </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Call to Action Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 30px;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $actionUrl }}" target="_blank" style="display: inline-block; background-color: #f97316; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 14px 32px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(249, 115, 22, 0.25);">
                                            @if ($action === 'request_docs')
                                                Unggah Dokumen Legalitas Sekarang &rarr;
                                            @else
                                                Buka Workspace Perusahaan &rarr;
                                            @endif
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; color: #94a3b8; font-size: 13px; line-height: 1.5; text-align: center;">
                                Jika tautan tombol tidak berfungsi, silakan salin tautan berikut ke peramban Anda:<br>
                                <a href="{{ $actionUrl }}" style="color: #f97316; word-break: break-all;">{{ $actionUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; color: #94a3b8; font-size: 12px;">
                                &copy; {{ date('Y') }} HUNTR (PT Huntr Teknologi Indonesia). Seluruh hak cipta dilindungi.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
