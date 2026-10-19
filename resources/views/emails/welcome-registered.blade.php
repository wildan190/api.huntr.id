<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selamat Datang di HUNTR</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-bottom: 3px solid #2563eb;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 700; letter-spacing: 1px;">HUNTR</h1>
                            <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px;">B2B Procurement & Sourcing Platform</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            <div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px;">
                                <span style="color: #059669; font-weight: 600; font-size: 14px;">✓ Registrasi Berhasil</span>
                            </div>

                            <h2 style="margin: 0 0 15px 0; color: #0f172a; font-size: 20px; font-weight: 600;">
                                Halo, {{ $user->name }}!
                            </h2>
                            <p style="margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;">
                                Selamat datang di <strong>HUNTR</strong>! Akun Anda telah berhasil dibuat. Anda sekarang dapat mulai menjelajahi platform pengadaan B2B pintar kami, mengelola permintaan pengadaan (RFQ), serta berpartisipasi dalam CrowdBuy.
                            </p>

                            <!-- Account Details Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px; width: 140px;">Nama Lengkap</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $user->name }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Email Terdaftar</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $user->email }}</td>
                                            </tr>
                                            @if($user->whatsapp)
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Nomor WhatsApp</td>
                                                <td style="padding: 6px 0; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $user->whatsapp }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding: 6px 0; color: #64748b; font-size: 13px;">Role Akun</td>
                                                <td style="padding: 6px 0; color: #2563eb; font-size: 14px; font-weight: 600; text-transform: capitalize;">{{ $role }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 25px 0; color: #475569; font-size: 14px; line-height: 1.6;">
                                Langkah selanjutnya: Masuk ke platform untuk menyelesaikan profil perusahaan Anda dan mengakses seluruh fitur pengadaan.
                            </p>

                            <!-- CTA Button -->
                            <div style="text-align: center; margin-bottom: 30px;">
                                <a href="{{ $loginUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; font-size: 15px; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);">
                                    Masuk ke Akun Anda
                                </a>
                            </div>

                            <p style="margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5;">
                                Jika Anda merasa tidak pernah mendaftar di HUNTR, silakan abaikan email ini atau hubungi tim bantuan kami.
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
                                Butuh bantuan? Hubungi kami di <a href="mailto:support@huntr.id" style="color: #2563eb; text-decoration: none;">support@huntr.id</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
