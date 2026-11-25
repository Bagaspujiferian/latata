<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP Verifikasi Email - {{ $appBrand }}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #0f172a; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #e2e8f0;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 560px; margin: 0 auto; background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden;">
        <tr>
            <td style="padding: 24px 32px; background-color: #0f172a; border-bottom: 1px solid #334155;">
                <h1 style="margin: 0; font-size: 18px; font-weight: 700; color: #38bdf8;">
                    {{ $appBrand }}
                </h1>
                <p style="margin: 4px 0 0; font-size: 12px; color: #94a3b8;">
                    Verifikasi Keamanan Akun &amp; Anti-Fraud Shield
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px;">
                <p style="margin: 0 0 16px; font-size: 15px; line-height: 1.6; color: #f1f5f9;">
                    Halo <strong>{{ $user->name }}</strong>,
                </p>
                <p style="margin: 0 0 24px; font-size: 14px; line-height: 1.6; color: #cbd5e1;">
                    Gunakan kode One-Time Password (OTP) 6-digit berikut untuk memverifikasi alamat email akun Anda di <strong>{{ $appBrand }}</strong>:
                </p>
                <div style="margin: 0 0 24px; padding: 20px; background-color: #0f172a; border: 1px dashed #38bdf8; border-radius: 10px; text-align: center;">
                    <span style="font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #38bdf8;">
                        {{ $otpCode }}
                    </span>
                </div>
                <p style="margin: 0 0 16px; font-size: 13px; line-height: 1.6; color: #94a3b8;">
                    Kode ini hanya berlaku selama <strong>{{ $ttlMinutes }} menit</strong> dan maksimal 5 kali percobaan verifikasi.
                </p>
                <p style="margin: 0; font-size: 12px; line-height: 1.6; color: #f87171;">
                    <strong>Peringatan Keamanan:</strong> Jangan pernah membagikan kode OTP ini kepada siapa pun, termasuk pihak yang mengaku sebagai staf {{ $appBrand }}.
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding: 16px 32px; background-color: #0f172a; border-top: 1px solid #334155; font-size: 11px; color: #64748b; text-align: center;">
                &copy; {{ date('Y') }} {{ $appBrand }}. Email otomatis, mohon tidak membalas pesan ini.
            </td>
        </tr>
    </table>
</body>
</html>
