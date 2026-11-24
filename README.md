. Yang Masih Kurang Buat Portofolio & Pembuktian (Paling Mendesak)

Etalase Depan (README.md + Visual UI): Ini satu-satunya hal paling krusial yang belum dikerjain. Sekarang kalau orang buka repo GitHub lu, mereka nggak langsung tahu kalau di dalamnya ada 96 test MySQL InnoDB, CI hijau 2/2, PHPStan Level 8 nol error, dan mesin Escrow 3-lapis. Butuh README.md tajam lengkap dengan lencana CI aktif, diagram Mermaid, dan beberapa screenshot halaman utama/admin.

Uji Tabrakan HTTP Paralel Sungguhan: PHPUnit itu jalannya antre satu per satu. Belum ada bukti stress-test sungguhan pakai k6 atau curl_multi yang nembak 20–50 request checkout secara serentak di milidetik yang sama ke server lokal untuk membuktikan FOR UPDATE SKIP LOCKED, idempotency key, dan kuota flash sale beneran tahan banting tanpa deadlock.

Pengujian Visual & Interaksi Browser (E2E): Test kita sekarang fokus di backend/HTTP Laravel. Interaksi JavaScript/Alpine.js di sisi browser (misal tombol modal checkout, drawer bulk upload, atau popup Midtrans Snap) belum tercover browser test seperti Playwright atau Laravel Dusk.

2. Yang Masih Kurang Kalau Mau Go-Live Komersial Sungguhan

Integrasi Pengiriman OTP & Email Nyata: Sekarang kode OTP dan notifikasi baru muter di database/log lokal. Untuk dipakai orang asli, lu perlu nyambungin SMTP transaksional (Resend, Postmark, atau Amazon SES) dan WhatsApp/SMS Gateway (misal Fonnte atau Twilio) plus rate limiter khusus biar saldo WA nggak dikuras spammer tombol "Kirim Ulang OTP".

Auto-Disbursement Payout API: Alur Maker-Checker kita saat ini mentok di pencatatan status APPROVED_PAID di database—artinya admin masih harus buka m-banking dan transfer manual satu per satu ke rekening seller. Di marketplace sungguhan, persetujuan Checker idealnya langsung menembak API transfer otomatis seperti Midtrans Iris, Xendit Disbursement, atau Flip for Business, lengkap dengan webhook status gagal/sukses dari bank.

Alerting Proaktif & Error Tracking: Dashboard scheduler_heartbeats baru ketahuan merah kalau admin rajin buka /admin/finance. Di produksi butuh Sentry buat langsung nangkap error 500 lengkap dengan stack trace, plus webhook alert ke Telegram/Discord otomatis tiap kali cron masuk status STALE/FAILED, failed_jobs menumpuk, atau audit-ledger tiba-tiba tidak BALANCED.

Automated Off-Site Backup (Anti-Kiamat Data): Ledger serapi apa pun nggak ada gunanya kalau SSD VPS rusak dan nggak ada cadangan. Butuh skrip backup MySQL terenkripsi otomatis (misal pakai spatie/laravel-backup atau cron mysqldump) yang dikirim rutin ke penyimpanan luar server seperti Cloudflare R2 atau AWS S3.

HTTP Security Headers & Pentest Manual: Konfigurasi Nginx/middleware belum dikunci ketat dengan header seperti Content-Security-Policy (CSP), Strict-Transport-Security, dan Referrer-Policy. Selain itu, semua endpoint input teks/upload/IDOR masih perlu disisir manual pakai OWASP ZAP atau Burp Suite, bukan cuma mengandalkan test yang kita tulis sendiri.