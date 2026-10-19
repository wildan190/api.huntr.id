<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi Aktivitas Login HUNTR</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-bottom: 3px solid #3b82f6;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 700; letter-spacing: 1px;">HUNTR</h1>
                            <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px;">Pemberitahuan Keamanan Akun</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                <span style="color: #1d4ed8; font-weight: 600; font-size: 14px;">ℹ Login Baru Terdeteksi</span>
                            </div>

                            <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                Halo, {{ $user->name }}
                            </h2>
                            <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                Kami mendeteksi adanya aktivitas masuk (login) ke akun HUNTR Anda dengan rincian berikut:
                            </p>

                            <!-- Login Details Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; width: 140px;">Waktu Login</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $loginTime }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Perangkat / Device</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $deviceName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Alamat IP</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $ipAddress }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 14px 16px; border-radius: 4px; margin-bottom: 25px;">
                                <p style="margin: 0; color: #991b1b; font-size: 13px; line-height: 1.5;">
                                    <strong>Bukan Anda?</strong> Jika Anda tidak mengenali aktivitas ini, kemungkinan akun Anda telah diakses tanpa izin. Segera ganti kata sandi Anda dan hubungi tim support kami di <a href="mailto:support@huntr.id" style="color: #dc2626; font-weight: 600;">support@huntr.id</a>.
                                </p>
                            </div>

                            <p style="margin: 0 0 25px 0; color: #475569; font-size: 14px; line-height: 1.6;">
                                Jika aktivitas ini memang Anda, Anda dapat mengabaikan email ini dengan aman.
                            </p>

                            <!-- CTA Button -->
                            <div style="text-align: center; margin-bottom: 20px;">
                                <a href="{{ $dashboardUrl }}" style="display: inline-block; background-color: #0f172a; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; font-size: 15px;">
                                    Buka Dashboard HUNTR
                                </a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center;">
                            <p style="margin: 0 0 6px 0; color: #64748b; font-size: 12px;">
                                &copy; {{ date('Y') }} HUNTR (PT Huntr Teknologi Indonesia). All rights reserved.
                            </p>
                            <p style="margin: 0; color: #94a3b8; font-size: 12px;">
                                Email otomatis ini dikirim untuk menjaga keamanan akun Anda di <a href="mailto:support@huntr.id" style="color: #2563eb; text-decoration: none;">support@huntr.id</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
