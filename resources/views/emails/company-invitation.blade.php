<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Undangan Bergabung ke Tim</title>
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
                            <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px;">Undangan Kolaborasi Tim</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            <div style="background-color: #fff7ed; border: 1px solid #ffedd5; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                <span style="color: #ea580c; font-weight: 600; font-size: 14px;">✉ Undangan Anggota Tim Baru</span>
                            </div>

                            <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                Anda Diundang Bergabung!
                            </h2>
                            <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                <strong>{{ $inviter->name }}</strong> telah mengundang Anda untuk bergabung ke dalam workspace <strong>{{ $company->name }}</strong> di platform HUNTR.
                            </p>

                            <!-- Invitation Details Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; width: 140px;">Perusahaan</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 700;">{{ $company->name }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Pengundang</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $inviter->name }} ({{ $inviter->email }})</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Peran (Role)</td>
                                                <td style="padding: 6px 0; color: #ea580c; font-size: 14px; font-weight: 700;">{{ $roleName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Tautan Berlaku Hingga</td>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">{{ $expiresAt }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 25px 0; color: #475569; font-size: 14px; line-height: 1.6;">
                                Klik tombol di bawah ini untuk menerima undangan dan langsung terhubung dengan rekan tim Anda:
                            </p>

                            <!-- CTA Button -->
                            <div style="text-align: center; margin-bottom: 30px;">
                                <a href="{{ $inviteUrl }}" style="display: inline-block; background-color: #f97316; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; box-shadow: 0 2px 4px rgba(249, 115, 22, 0.3);">
                                    Terima Undangan Tim
                                </a>
                            </div>

                            <p style="margin: 0 0 10px 0; color: #64748b; font-size: 12px; line-height: 1.5;">
                                Atau salin tautan berikut ke browser Anda jika tombol tidak dapat diklik:
                            </p>
                            <p style="margin: 0 0 20px 0; word-break: break-all; background-color: #f1f5f9; padding: 8px 12px; border-radius: 6px; font-size: 12px; color: #0f172a;">
                                {{ $inviteUrl }}
                            </p>

                            <p style="margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5;">
                                Jika Anda tidak merasa mengenal pengundang atau perusahaan ini, Anda dapat mengabaikan email ini.
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
                                Pertanyaan? Hubungi tim support kami di <a href="mailto:support@huntr.id" style="color: #f97316; text-decoration: none;">support@huntr.id</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
