# PRD Fase 4 — Production Hardening, Security, Monetization & Finance P&L (`latata` / NexaTrade)

## 1. Tujuan & Batasan Sistem (Zero-Breakage Guardrails)
Fase 4 menyempurnakan **NexaTrade Enterprise Engine (`latata`)** pada **Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB (`db_latata` / `db_latata_test`), Tailwind CSS v4, dan Alpine.js** menjadi sistem siap produksi tanpa celah jalan buntu (*logic dead-end*), dilengkapi kontrol keamanan autentikasi, PIN transaksi, rate limiting, rekonsiliasi otomatis, monetisasi kredit iklan, ekspor dokumen PDF/CSV, dan dasbor P&L keuangan perusahaan.

### Aturan Mutlak
1. **Zero Regression (41/41 Test Lama Wajib Hijau):** Seluruh test di `NexaTradeEnterpriseEngineTest.php`, `Fase1CommerceUpgradeTest.php`, `Fase2TrustAndEngagementTest.php`, dan `Fase3GrowthWarrantyAndCmsTest.php` wajib tetap lulus 100% tanpa melemahkan satu pun *assertion*.
2. **Kompatibilitas PIN Dompet 6-Digit:** Verifikasi PIN wajib diberlakukan secara ketat pada setiap user yang sudah mengaktifkan PIN (`users.wallet_pin_hash !== null`). Untuk akun *seeder* lama yang `wallet_pin_hash`-nya masih `null`, alur test Fase 0–3 tetap berjalan mulus tanpa error, sementara di antarmuka web ditampilkan banner ajakan aktivasi PIN.
3. **Pagar Produksi Demo Switcher:** Tambahkan `config('nexatrade.demo_switcher_enabled', env('NEXATRADE_DEMO_SWITCHER', env('APP_ENV') !== 'production'))`. Saat bernilai `false` (default di `production`), seluruh *route* `DemoAuthController` wajib mengembalikan **HTTP 404** dan komponen Floating Dev Switcher di `layouts/app.blade.php` tidak dirender sama sekali.
4. **Rate Limiter Aman untuk Test Suite:** Pasang `RateLimiter` ketat di `AppServiceProvider` dengan batas realistis per `user_id|ip` agar test Fase 0–3 tidak tersandung HTTP 429 secara tidak sengaja, namun tetap teruji penuh oleh test serangan *brute-force* khusus di `Fase4HardeningAndFinanceTest.php`.
5. **Safe ENUM Expansion:** Saat memperluas ENUM `wallet_mutations.mutation_type`, periksa seluruh nilai ENUM aktual dari migrasi Fase 0–3 terlebih dahulu dan pertahankan semuanya tanpa menghapus satu nilai pun.
6. **Light Mode Anti-Slop Design System:** Pertahankan konsistensi Light Mode Itemku/Tokopedia (`#F4F6F9` canvas, `#FFFFFF` cards, `1px solid #E4E8EE` border, `#0068E1` Trust Blue, `#00AA5B` Safe Green, `#F05A28` Urgency Orange, Plus Jakarta Sans + JetBrains Mono `tabular-nums`, `.btn-tactile`, `.card-elevate`, `.skeleton-shimmer`). Nol dark slate, nol gradien indigo/ungu, nol emoji Unicode mentah (gunakan inline Lucide SVG `stroke-width="1.75"`).

---

## 2. Skema Database Relasional (4 Tabel Baru + Migrasi Aditif)

### Tabel Baru (MySQL 8.4 InnoDB)
1. **`ad_credit_packages`** (Master Paket Kredit Sundul & Promosi Toko)
   - `id`, `code` VARCHAR(40) UNIQUE, `name` VARCHAR(100), `credits_amount` INT UNSIGNED, `bonus_credits` INT UNSIGNED DEFAULT 0, `price` DECIMAL(15,2), `badge_label` VARCHAR(40) NULL (misal: `Paling Laris`, `Hemat 20%`), `is_active` BOOLEAN DEFAULT true, `sort_order` TINYINT UNSIGNED DEFAULT 0, timestamps.

2. **`ad_credit_purchases`** (Riwayat Pembelian Kredit Iklan Seller — Sumber Pendapatan Platform)
   - `id`, `purchase_code` VARCHAR(40) UNIQUE, `seller_id` (FK `seller_profiles.user_id` cascade), `package_id` (FK `ad_credit_packages` restrict), `credits_granted` INT UNSIGNED, `amount_paid` DECIMAL(15,2), `payment_method` ENUM(`available_balance`, `midtrans_gateway`), `status` ENUM(`PAID`, `PENDING`, `FAILED`) DEFAULT `PAID`, timestamps.
   - Index: `(seller_id, created_at)` dan `(status, created_at)`.

3. **`debt_repayments`** (Riwayat Pelunasan Tunggakan `clawback_debt_balance` Seller)
   - `id`, `repayment_code` VARCHAR(40) UNIQUE, `seller_id` (FK `seller_profiles.user_id` cascade), `amount` DECIMAL(15,2), `payment_source` ENUM(`available_balance`, `gateway_direct`), `debt_before` DECIMAL(15,2), `debt_after` DECIMAL(15,2), `auto_unsuspended` BOOLEAN DEFAULT false, `notes` VARCHAR(255) NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP.
   - Index: `(seller_id, created_at)`.

4. **`ledger_audit_snapshots`** (Rekaman Audit Rekonsiliasi Buku Besar Global)
   - `id`, `audit_code` VARCHAR(40) UNIQUE, `status` ENUM(`BALANCED`, `DISCREPANCY`), `total_wallets_pending` DECIMAL(18,2), `total_wallets_available` DECIMAL(18,2), `total_wallets_deposit` DECIMAL(18,2), `total_custody_wallets` DECIMAL(18,2), `total_ledger_net` DECIMAL(18,2), `discrepancy_amount` DECIMAL(18,2), `users_checked` INT UNSIGNED, `discrepancy_details` JSON NULL, `triggered_by` VARCHAR(60), `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP.
   - Index: `(status, created_at)`.

### Migrasi Aditif pada Tabel Eksisting
- **`users`**: tambah `wallet_pin_hash` VARCHAR(255) NULL, `wallet_pin_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0, `wallet_pin_locked_until` TIMESTAMP NULL, `wallet_pin_set_at` TIMESTAMP NULL.
- **`wallet_mutations`**: perluas ENUM `mutation_type` (mempertahankan SELURUH nilai lama Fase 0–3) dengan menambahkan `'AD_CREDIT_PURCHASE'`.

---

## 3. Spesifikasi Service Layer & Artisan Workers

### A. Keamanan Auth, PIN Dompet & Rate Limiting
1. **`AuthController` & `WalletPinService`**
   - Autentikasi produksi penuh tanpa *starter kit* yang menimpa layout: `GET/POST /login`, `GET/POST /register` (otomatis membuat baris `wallets` 0.00 di dalam transaksi & login), `POST /logout`, dan halaman Pengaturan Keamanan `/account/security` (ganti password + atur/ubah PIN Dompet 6 digit).
   - **`WalletPinService`**:
     - `setPin(int $userId, string$pin, ?string $currentPassword = null)`: Validasi ketat `/^\d{6}$/`, tolak PIN lemah (`000000`, `123456`, `111111`), simpan `Hash::make($pin)` ke `wallet_pin_hash`, reset `wallet_pin_attempts = 0`, `wallet_pin_locked_until = null`. - `verifyPinOrFail(int $userId, ?string $submittedPin, bool$requireOnlyIfSet = true)`:
       - Kunci baris `users` (`lockForUpdate()`).
       - Jika `wallet_pin_hash === null` dan `$requireOnlyIfSet === true`, loloskan (menjaga kompatibilitas test lama). - Jika `wallet_pin_locked_until` masih di masa depan, lempar `WalletPinLockedException` ("PIN terkunci sementara karena 5x salah. Coba lagi dalam X menit."). - Jika PIN salah: naikkan `wallet_pin_attempts`. Pada percobaan gagal ke-5, kunci selama **15 menit** (`wallet_pin_locked_until = now()->addMinutes(15)`), lalu lempar `InvalidWalletPinException`. - Jika PIN benar: reset `wallet_pin_attempts = 0` dan `wallet_pin_locked_until = null`. - Wajib terpasang pada: pengajuan Withdraw (`SellerDashboardController`), pembayaran Checkout memakai saldo dompet (`CheckoutController`), pelunasan hutang clawback via saldo (`SellerDebtController`), dan pembelian paket Ad Credits via saldo (`SellerAdCreditController`).  2. **Rate Limiter & Nickname Caching (`AppServiceProvider` & `GameNicknameService`)** - Daftarkan named `RateLimiter` di `AppServiceProvider::boot()`: - `auth-login`: 10 percobaan/menit per email+IP. - `voucher-preview`: 20 request/menit per user/IP (cegah *brute-force* tebak kode promo). - `nickname-verify`: 30 request/menit per user/IP. - `wallet-sensitive`: 10 request/menit per user (withdraw, verifikasi PIN, beli ad credits). - `chat-send`: 40 pesan/menit per user. - **`GameNicknameService`**: Tambahkan `Cache::remember("nickname:{$gameCode}:{$userId}:{$zoneId}", now()->addMinutes(15), ...)` agar pengecekan ID yang sama tidak memboroskan kuota API eksternal, dan sertakan flag `'cached' => true/false` di respons JSON.

### B. Penutupan Celah Operasional & Monetisasi
3. **`H2HReconciliationService` & Command `php artisan nexatrade:reconcile-h2h`**
   - Mencari pesanan `orders` dengan `delivery_method = 'api_h2h'`, `status = 'PROCESSING'`, dan `updated_at <= now()->subMinutes($staleMinutes)` (default 5 menit, bisa dioverride via opsi `--minutes=0` untuk uji coba). - Menggunakan **`h2h_reference_id` yang sama persis** (dilarang membuat `ref_id` baru!) untuk memanggil `DigiflazzH2HService::checkOrderStatus($order)`.
   - Jika provider melaporkan `sukses` -> delegasikan ke `handleH2HWebhook($refId, 'sukses',$sn)` (otomatis `DELIVERED` + terbitkan garansi jika ada).
   - Jika provider melaporkan `gagal` -> delegasikan ke `handleH2HWebhook($refId, 'gagal',$reason)` (otomatis `REFUNDED` sebesar `buyer_payable_amount` + pulihkan stok).
   - Jika masih `pending` namun sudah melewati `sla_deadline_at` + grace period 10 menit -> tandai flag rekonsiliasi atau log untuk eskalasi admin.
   - Daftarkan di `routes/console.php` berjalan otomatis setiap 5 menit.

4. **`SellerDebtRecoveryService`**
   - `repayDebtFromAvailableBalance(int $sellerId, float$amount)`:
     - Jalankan dalam `DB::transaction()`, kunci `wallets` dan `seller_profiles` (`lockForUpdate()`).
     - Validasi `clawback_debt_balance > 0`, `$amount > 0`, dan cap `$payAmount = min($amount,$wallet->clawback_debt_balance)`.
     - Validasi `available_balance >= $payAmount`. - Kurangi `available_balance -= $payAmount`, kurangi `clawback_debt_balance -= $payAmount`, catat mutasi `SELLER_DEBT_RECOVERY` (`DEBIT` pada `available`) dengan `reference_code` unik di `wallet_mutations`. - Catat baris di `debt_repayments`. - **Auto-Unsuspend:** Jika `clawback_debt_balance == 0.00` dan toko sebelumnya di-suspend karena tunggakan garansi, otomatis ubah `seller_profiles.is_suspended = false`, `suspension_reason = null`, `is_store_open = true`, dan kirim notifikasi `escrow` ke penjual! - `repayDebtViaGatewaySettlement(int $sellerId, float $amount, string$gatewayRef)`: Jalur pelunasan langsung via simulasi/gateway Midtrans ketika `available_balance` penjual Rp 0, mencatat `debt_repayments` (`gateway_direct`), dan menjalankan auto-unsuspend saat lunas.

5. **`AdCreditAndStoreLevelService`**
   - `purchasePackageWithWallet(int $sellerId, int$packageId)`:
     - Jalankan dalam `DB::transaction()`, kunci `wallets` & `seller_profiles` (`lockForUpdate()`).
     - Cek `available_balance >= $package->price`. - Debit `available_balance` melalui `EscrowLedgerService` (`mutation_type = 'AD_CREDIT_PURCHASE'`, `reference_code = "ADCRED-{$purchaseCode}"`).
     - Tambahkan `seller_profiles.ad_credits += ($package->credits_amount +$package->bonus_credits)`.
     - Simpan `ad_credit_purchases` (`status = 'PAID'`) dan kirim notifikasi `promo`.
   - `boostProductFeatured(int $sellerId, int$productId, int $creditCost = 3)`: Potong 3 `ad_credits` secara atomik untuk menandai produk `is_featured = true` + `last_bumped_at = now()`. - `recalculateStoreLevel(int $sellerId)`:
     - Level 1 (*Juragan Baru*): `< 25` pesanan selesai (diskon komisi 0%).
     - Level 2 (*Juragan Pro*): `>= 25` pesanan selesai & `avg_delivery_minutes <= 10.0` (diskon komisi 0.5% dari tarif kategori, min komisi 1%).
     - Level 3 (*Juragan Sultan*): `>= 100` pesanan selesai & `avg_delivery_minutes <= 8.0` (diskon komisi 1.0% dari tarif kategori, min komisi 1%).
     - *(Catatan: Seller seeded memiliki < 25 pesanan selesai sehingga tarif 5% di semua test lama tidak berubah sama sekali.)*

### C. Admin Finance P&L, Rekonsiliasi Ledger Global & Ekspor PDF/CSV
6. **`FinanceAndLedgerAuditService` & Command `php artisan nexatrade:audit-ledger`**
   - **`runGlobalLedgerAudit(string $triggeredBy = 'artisan')`**:
     - Untuk setiap user di `wallets`, hitung saldo teoritis dari seluruh baris `wallet_mutations` (`SUM(CASE WHEN direction='CREDIT' THEN amount ELSE -amount END)` per `balance_bucket`: `pending`, `available`, `security_deposit`).
     - Bandingkan dengan kolom aktual di tabel `wallets`.
     - Catat hasil ke `ledger_audit_snapshots` (`status = 'BALANCED'` jika selisih tepat `0.00`, atau `DISCREPANCY` lengkap dengan daftar `user_id` bermasalah di `discrepancy_details`).
   - **`getFinancePnLSummary(?Carbon $from = null, ?Carbon $to = null): array`**:
     - Menghitung metrik akurat dari database MySQL:
       1. **GMV (Gross Merchandise Value):** Total `orders.gross_amount` pada pesanan `COMPLETED` / `DELIVERED` / `PROCESSING` / `PAID_ESCROW`.
       2. **Pendapatan Komisi Kotor:** Total `orders.platform_commission` pada pesanan `COMPLETED`.
       3. **Pendapatan Penjualan Ad Credits:** Total `ad_credit_purchases.amount_paid` (`PAID`).
       4. **Pendapatan Biaya Admin Withdraw:** Total `withdrawal_requests.admin_fee` (`APPROVED_PAID`).
       5. **Beban Subsidi Voucher Platform:** Total `orders.platform_discount_amount` pada pesanan `COMPLETED` (bukan yang di-refund!).
       6. **Beban Dana Jaminan Hackback (Platform Exposure):** Total `warranty_claims.recorded_seller_debt` dikurangi total `debt_repayments.amount`.
       7. **Laba Bersih Platform (Net Platform Revenue):** `(Komisi + Ad Credits + Admin Fee Withdraw) - (Subsidi Voucher Platform + Net Hackback Exposure)`.
       8. **Total Dana Titipan Wajib di Rekening Bank (Custody Liability):** `SUM(pending_balance + available_balance + security_deposit_balance)` seluruh pengguna + status audit terakhir (`BALANCED` / `DISCREPANCY`).

7. **Dokumen Invoice PDF & Export CSV (`DocumentExportService`)**
   - Install **`barryvdh/laravel-dompdf`** via Composer agar menghasilkan file biner **`application/pdf`** sungguhan.
   - `GET /invoices/{invoice_code}/pdf`: Unduh Invoice Pembayaran PDF resmi (otorisasi ketat: hanya pembeli pemilik invoice atau Admin, selain itu 403) berisi rincian Split-Order, diskon voucher toko/platform, status pembayaran, dan QR/kode referensi.
   - `GET /buyer/orders/{order_code}/pdf`: Unduh Bukti Transaksi & Sertifikat Garansi PDF (jika ada `order_warranties`).
   - `GET /seller/exports/orders.csv` & `GET /seller/exports/mutations.csv`: Streaming download CSV (`text/csv; charset=UTF-8` dengan BOM agar rapi dibuka di Microsoft Excel) mendukung filter rentang tanggal `?from=YYYY-MM-DD&to=YYYY-MM-DD`.

---

## 4. Antarmuka Light Mode & Route Utama
- **Auth & Keamanan (`/login`, `/register`, `/account/security`):** Halaman Login & Register bersih bergaya Tokopedia/Itemku (kartu putih terpusat, validasi inline, tanpa emoji), plus tab **Keamanan & PIN Dompet 6-Digit** dengan indikator status PIN (Aktif / Belum Diatur) dan status *lockout*.
- **Navbar & Floating Dev Switcher (`layouts/app.blade.php`):**
  - Tampilkan tombol `Masuk` / `Daftar` saat belum login, dan menu `Keamanan & PIN` di dropdown user saat login.
  - Sembunyikan Floating Dev Switcher sepenuhnya saat `config('nexatrade.demo_switcher_enabled') === false`.
- **Seller Studio — Promosi & Pelunasan Hutang (`/seller/ad-credits`, `/seller/wallet`):**
  - Halaman **Beli Kredit Sundul & Promosi** (`/seller/ad-credits`): Kartu paket kredit bersih, tombol beli via Saldo Aktif (meminta input PIN 6-digit jika PIN aktif), badge **Level Toko (Lv 1–3)** dengan progress bar menuju diskon komisi berikutnya, serta tombol **Export CSV Penjualan & Mutasi**.
  - Upgrade `/seller/wallet`: Jika `clawback_debt_balance > 0`, tampilkan kartu aksi **"Lunasi Tunggakan Clawback & Aktifkan Kembali Toko"** (pilihan bayar dari Saldo Aktif atau Simulasi Transfer Gateway).
- **Buyer Invoice & Order Detail:** Tambahkan tombol **"Unduh Invoice PDF"** dan **"Unduh Bukti & Garansi PDF"** pada `checkout/invoice.blade.php` dan `buyer/orders/show.blade.php`.
- **Admin Finance P&L & Ledger Audit (`/admin/finance`):**
  - Kartu metrik P&L lengkap (GMV, Pendapatan Komisi, Pendapatan Ad Credits, Beban Subsidi Voucher, Net Platform Revenue, Total Custody Liability).
  - Panel **Rekonsiliasi Buku Besar Global** dengan status hijau `BALANCED (Selisih Rp 0)` + tombol **"Jalankan Audit Ledger Sekarang"** (`POST /admin/finance/audit-ledger`) & tombol **"Jalankan Reconcile H2H"** (`POST /admin/finance/reconcile-h2h`) dengan animasi *Light Mode shimmer skeleton* saat berjalan.
  - Tabel riwayat `ledger_audit_snapshots`, `ad_credit_purchases`, dan `debt_repayments`.

---

## 5. Seeder & Feature Test (`Fase4HardeningAndFinanceTest.php`)
- **Seeder:** Update `DatabaseSeeder.php` (non-breaking) dengan:
  - 3 paket `ad_credit_packages`: `STARTER-10` (10 kredit, Rp 15.000), `PRO-30` (30 + 5 bonus, Rp 40.000, "Paling Laris"), `SULTAN-100` (100 + 25 bonus, Rp 120.000, "Hemat 20%").
  - 1 riwayat `ad_credit_purchases` terbayar (pastikan konsisten dengan mutasi `wallet_mutations` agar audit ledger global langsung `BALANCED`!).
  - 1 snapshot awal `ledger_audit_snapshots` hasil menjalankan `FinanceAndLedgerAuditService::runGlobalLedgerAudit('seeder')` sehingga `/admin/finance` langsung menampilkan data hidup dan status `BALANCED`.
  - *(Biarkan `wallet_pin_hash = null` pada akun seeder default agar 41 test lama tetap 100% lulus tanpa perubahan.)*
- **Automated Tests (`tests/Feature/Fase4HardeningAndFinanceTest.php` di MySQL InnoDB):**
  1. **Production Auth & Demo Switcher Guard:** Register user baru membuat wallet Rp 0, Login & Logout bekerja; ketika `config(['nexatrade.demo_switcher_enabled' => false])`, route Demo Switcher mengembalikan **HTTP 404**.
  2. **Wallet PIN 6-Digit & 5x Lockout:** User mengatur PIN 6 digit (menolak PIN lemah `123456`), withdraw gagal tanpa PIN / PIN salah, percobaan salah ke-5 mengunci PIN selama 15 menit (`wallet_pin_locked_until`), dan withdraw berhasil dengan PIN benar.
  3. **Rate Limiter & Nickname Cache:** Menembak endpoint `voucher-preview` melebihi batas memicu **HTTP 429**; panggilan kedua `verify-nickname` dengan ID sama mengembalikan `'cached' => true`.
  4. **H2H Reconciliation Worker:** Order `api_h2h` nyangkut `PROCESSING` > 5 menit direkonsiliasi oleh `php artisan nexatrade:reconcile-h2h` menggunakan `h2h_reference_id` yang sama hingga otomatis `DELIVERED` atau `REFUNDED` tanpa duplikasi ref ID.
  5. **Clawback Debt Repayment & Auto-Unsuspend:** Seller dengan `clawback_debt_balance = 100000` dan `is_suspended = true` melunasi hutang -> `clawback_debt_balance` menjadi `0.00`, `debt_repayments` tercatat, dan toko otomatis `is_suspended = false` + `is_store_open = true`.
  6. **Ad Credits Purchase & Ledger Integrity:** Seller membeli paket Ad Credits via `available_balance` -> saldo terpotong atomik, `ad_credits` bertambah sesuai paket + bonus, mutasi `AD_CREDIT_PURCHASE` tercatat.
  7. **PDF Invoice & CSV Export Authorization:** Pembeli pemilik invoice mendapat HTTP 200 dengan header `application/pdf`, pembeli lain mendapat HTTP 403; Seller export orders & mutations mengembalikan HTTP 200 `text/csv` valid.
  8. **Global Ledger Reconciliation & Admin Finance P&L:** Menjalankan `php artisan nexatrade:audit-ledger` menghasilkan status `BALANCED` dengan `discrepancy_amount = 0.00`, dan `/admin/finance` menampilkan kalkulasi P&L yang akurat.

---

## 6. Checklist Progres Eksekusi
- [ ] Checkpoint 1: Install `barryvdh/laravel-dompdf`, buat 4 migrasi baru + migrasi aditif (perluasan ENUM aman) + model Eloquent, dan verifikasi migrasi di MySQL InnoDB.
- [ ] Checkpoint 2: Implementasikan `WalletPinService`, `H2HReconciliationService`, `SellerDebtRecoveryService`, `AdCreditAndStoreLevelService`, `FinanceAndLedgerAuditService`, `DocumentExportService`, Rate Limiters, Nickname Cache, serta Artisan commands (`nexatrade:reconcile-h2h`, `nexatrade:audit-ledger`).
- [ ] Checkpoint 3: Bangun Auth produksi (`/login`, `/register`, `/account/security`), pagar Demo Switcher, Seller Ad Credits & Debt Repayment UI, tombol PDF/CSV, Admin Finance P&L Dashboard (`/admin/finance`), dan perbarui `DatabaseSeeder.php` + `npm run build`.
- [ ] Checkpoint 4: Tulis `tests/Feature/Fase4HardeningAndFinanceTest.php`, centang checklist di `docs/prd-fase-4-hardening.md`, dan pastikan SELURUH test (Fase 0, 1, 2, 3, dan 4) lulus 100% di `db_latata_test` serta `php artisan nexatrade:audit-ledger` melaporkan `BALANCED`.