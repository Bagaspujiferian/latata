# PRD Fase 5 — Enterprise Polish, Idempotency, Split Settlement & Anti-Gestun (`latata` / NexaTrade)

## 1. Tujuan & Batasan Sistem (Zero-Breakage Guardrails)
Fase 5 menutup 5 celah produksi terakhir pada **NexaTrade Enterprise Engine (`latata`)** (**Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB `db_latata` / `db_latata_test`, Tailwind CSS v4, Alpine.js**): *Time-Boxed Ad Boost*, *Anti-Gaming Store Level*, *HTTP Idempotency Guard*, *Partial Dispute Split Settlement*, *Buyer Wallet Top-Up Anti-Gestun*, *Cross-User Bank Blind Hash*, serta *Self-Service Reset Password & Reset PIN 6-Digit*.

### Aturan Mutlak
1. **Zero Regression (49/49 Test Lama Wajib Hijau):** Seluruh test di `NexaTradeEnterpriseEngineTest`, `Fase1CommerceUpgradeTest`, `Fase2TrustAndEngagementTest`, `Fase3GrowthWarrantyAndCmsTest`, dan `Fase4HardeningAndFinanceTest` wajib tetap lulus 100% tanpa mengubah atau melemahkan *assertion* lama.
2. **Global Ledger Balance Law:** Setelah `migrate:fresh --seed` dan setelah seluruh operasi Fase 5 (Top-Up, Split Settlement, Boost), `php artisan nexatrade:audit-ledger` wajib melapor **`BALANCED` (`discrepancy_amount = 0.00`)**.
3. **Backward-Compatible Idempotency Middleware:** Middleware `EnsureIdempotentRequest` memeriksa header `Idempotency-Key` atau field form `_idempotency_key`. Jika request lama (termasuk test Fase 0–4) tidak menyertakan key, loloskan normal; jika key disertakan (dan wajib disuntikkan ke semua form transaksi uang di Blade via UUID), kunci secara atomik terhadap *double-click* / *retry*.
4. **No External HTTP Inside Row-Lock Transactions:** Pastikan panggilan jaringan eksternal (Midtrans live / Digiflazz H2H / pengiriman email notifikasi pemulihan) dijalankan di luar `DB::transaction()` atau via `DB::afterCommit()` agar *row lock* InnoDB tetap singkat.
5. **Safe ENUM Expansion:** Saat memperluas ENUM `wallet_mutations.mutation_type` dan `user_notifications.category`, baca definisi migrasi terbaru (`000043`) terlebih dahulu dan pertahankan seluruh nilai ENUM sebelumnya.
6. **Light Mode Anti-Slop Design System:** Patuhi estetika Light Mode Itemku/Tokopedia (`#F4F6F9`, `#FFFFFF`, `1px solid #E4E8EE`, `#0068E1`, `#00AA5B`, `#F05A28`, Plus Jakarta Sans + JetBrains Mono `tabular-nums`, `.btn-tactile`, `.card-elevate`, `.skeleton-shimmer`). Nol dark slate, nol gradien indigo/ungu, nol emoji Unicode mentah (pakai inline Lucide SVG `stroke-width="1.75"`).

---

## 2. Skema Database Relasional (4 Tabel Baru + Migrasi Aditif)

### Tabel Baru (MySQL 8.4 InnoDB)
1. **`product_boosts`** (Time-Boxed Ad Boost History & Expiry Tracking)
   - `id`, `product_id` (FK `products` cascade), `seller_id` (FK `seller_profiles.user_id` cascade), `boost_type` ENUM(`featured`, `bump`), `credits_spent` INT UNSIGNED, `starts_at` TIMESTAMP, `ends_at` TIMESTAMP, `status` ENUM(`active`, `expired`) DEFAULT `active`, timestamps.
   - Index: `(status, ends_at)` dan `(product_id, boost_type, status)`.

2. **`idempotency_keys`** (Pelindung Anti-Double-Click & Network Retry)
   - `id`, `user_id` (FK `users` cascade), `idempotency_key` VARCHAR(100), `http_method` VARCHAR(10), `route_path` VARCHAR(160), `payload_hash` CHAR(64), `status` ENUM(`processing`, `completed`, `failed`) DEFAULT `processing`, `http_status` SMALLINT UNSIGNED NULL, `response_snapshot` JSON NULL, `expires_at` TIMESTAMP, timestamps.
   - Unique: `(user_id, idempotency_key)` | Index: `(expires_at)`.

3. **`wallet_topups`** (Jalur Resmi Top-Up Dompet Pembeli via Midtrans/QRIS)
   - `id`, `topup_code` VARCHAR(40) UNIQUE, `user_id` (FK `users` cascade), `amount` DECIMAL(15,2), `payment_channel` VARCHAR(40) DEFAULT `midtrans_snap`, `gateway_reference` VARCHAR(100) NULL UNIQUE, `status` ENUM(`UNPAID`, `PAID`, `EXPIRED`, `FAILED`) DEFAULT `UNPAID`, `paid_at` TIMESTAMP NULL, `expires_at` TIMESTAMP, timestamps.
   - Index: `(user_id, status, created_at)`.

4. **`wallet_pin_reset_tokens`** (Pemulihan Aman PIN Dompet 6-Digit)
   - `id`, `user_id` (FK `users` cascade), `token_hash` CHAR(64) UNIQUE, `expires_at` TIMESTAMP, `used_at` TIMESTAMP NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP.
   - Index: `(user_id, expires_at)`.

### Migrasi Aditif pada Tabel Eksisting (Default Aman)
- **`products`**: tambah `featured_until` TIMESTAMP NULL setelah `is_featured`, plus index `(is_featured, featured_until)`.
- **`wallets`**: tambah `non_withdrawable_portion` DECIMAL(15,2) NOT NULL DEFAULT 0.00 setelah `available_balance` (bagian dari `available_balance` hasil top-up yang hanya boleh dibelanjakan, tidak boleh ditarik tunai/gestun).
- **`user_bank_accounts`**: tambah `account_blind_hash` CHAR(64) NULL dan `active_account_lock_hash` CHAR(64) NULL UNIQUE (mencegah 1 nomor rekening bank dipakai lintas akun sindikat tuyul; di-set `NULL` saat rekening di-soft-delete).
- **`orders`**: tambah `dispute_buyer_refund_percent` TINYINT UNSIGNED NULL, `dispute_buyer_refund_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00, `dispute_seller_release_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00.
- **`wallet_mutations`**: perluas ENUM `mutation_type` (mempertahankan seluruh nilai Fase 0–4) dengan menambahkan `'WALLET_TOPUP'`, `'DISPUTE_PARTIAL_REFUND'`, `'DISPUTE_PARTIAL_RELEASE'`.

---

## 3. Spesifikasi Service Layer, Middleware & Artisan Workers

### A. Time-Boxed Ad Boost & Anti-Gaming Store Level
1. **Perbarui `AdCreditAndStoreLevelService`**
   - **`boostProductFeatured(int $sellerId, int$productId, int $creditCost = 3, int$durationDays = 7)`**:
     - Kunci `seller_profiles` & `products` (`lockForUpdate()`).
     - Jika produk sudah punya boost `featured` aktif, perpanjang `ends_at = max(now(), $product->featured_until)->addDays($durationDays)`; jika belum, `ends_at = now()->addDays($durationDays)`. - Catat baris di `product_boosts` (`boost_type = 'featured'`, `status = 'active'`), set `products.is_featured = true`, `products.featured_until = $endsAt`, `products.last_bumped_at = now()`.
   - **`sweepExpiredBoosts(): int`** & Command **`php artisan nexatrade:expire-boosts`** (dijadwalkan tiap 15 menit di `routes/console.php`):
     - Cari `product_boosts` berstatus `active` dengan `ends_at <= now()`. Tandai `status = 'expired'` dan set `products.is_featured = false` (jika produk tidak memiliki boost `featured` lain yang masih aktif).
   - **Anti-Gaming `recalculateStoreLevel(int $sellerId)`**: - Hitung hanya pesanan `COMPLETED` dengan **`gross_amount >= 10000`** (blokir order dummy Rp 100). - Hitung **`qualifying_orders`**, **`unique_buyers` (`COUNT(DISTINCT buyer_id)`)**, dan **`qualifying_gmv` (`SUM(gross_amount)`)**. - **Level 1 (*Juragan Baru*):** Default (diskon komisi 0\%). - **Level 2 (*Juragan Pro*):** `qualifying_orders >= 25` AND `unique_buyers >= 10` AND `qualifying_gmv >= 2500000` AND `avg_delivery_minutes <= 10.0` (diskon komisi 0.5\%, batas bawah komisi 1\%). - **Level 3 (*Juragan Sultan*):** `qualifying_orders >= 100` AND `unique_buyers >= 35` AND `qualifying_gmv >= 15000000` AND `avg_delivery_minutes <= 8.0` (diskon komisi 1.0\%, batas bawah komisi 1\%).  ### B. HTTP Idempotency Guard 2. **Middleware `App\Http\Middleware\EnsureIdempotentRequest` (alias: `idempotent`)** - Terapkan pada route sensitif POST: checkout (`checkout.process`), pengajuan withdraw (`seller.withdraw`), beli ad credits (`seller.ad-credits.purchase`), pelunasan hutang (`seller.debt.repay`), dan top-up dompet (`buyer.wallet.topup`). - Alur kerja atomik saat `Idempotency-Key` / `_idempotency_key` dikirim: 1. Hitung `$payloadHash = hash('sha256', json_encode($request->except(['_token', '_idempotency_key'])));`. 2. Coba `INSERT` baris baru ke `idempotency_keys` (`status = 'processing'`, `expires_at = now()->addHours(24)`). 3. Jika terkena `UniqueConstraintViolationException`, baca baris yang ada: - Jika `payload_hash !== $payloadHash` -> balas **HTTP 422** (atau redirect error "Idempotency key dipakai ulang dengan payload berbeda").
        - Jika `status === 'processing'` -> balas **HTTP 409** ("Permintaan sebelumnya masih diproses, mohon tunggu.").
        - Jika `status === 'completed'` -> jangan jalankan ulang controller! Untuk JSON kembalikan cached response (`X-Idempotency-Replayed: true`); untuk web form redirect kembali dengan pesan info aman ("Transaksi ini sudah diproses sebelumnya — duplikasi dicegah.").
     4. Setelah controller selesai: jika HTTP `< 500`, perbarui `status = 'completed'`, simpan `http_status` & ringkasan `response_snapshot`; jika exception/500, hapus atau tandai `failed` agar retry sah tetap bisa dilakukan.
   - Suntikkan `<input type="hidden" name="_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">` + kunci tombol submit Alpine.js (`:disabled="submitting"`) pada form-form sensitif di Blade.

### C. Partial Dispute Split Settlement
3. **Perbarui `OrderStateAndSlaService::adminResolveDispute(...)` & `EscrowLedgerService`**
   - Pertahankan keputusan lama (`REFUND_BUYER`, `RELEASE_SELLER`) 100% utuh, lalu tambahkan keputusan baru **`SPLIT_SETTLEMENT`** dengan parameter `int $buyerRefundPercent` (validasi ketat `1..99`).
   - Rumus akuntansi presisi di dalam `DB::transaction()` (kunci `orders` + `wallets` pembeli & penjual ascending `ORDER BY user_id ASC`):
     - Basis pembeli `$buyerBasis = $order->buyer_payable_amount > 0 ?$order->buyer_payable_amount : $order->gross_amount`. - `$buyerRefundAmount = round($buyerBasis * ($buyerRefundPercent / 100), 2)`.
     - `$sellerReleaseAmount = round($order->seller_escrow_amount * ((100 - $buyerRefundPercent) / 100), 2)`. - `$sellerClawbackPending = round($order->seller_escrow_amount -$sellerReleaseAmount, 2)`.
     - Mutasi atomik di `EscrowLedgerService`:
       1. Debit `pending_balance` penjual sebesar `$sellerReleaseAmount` + Kredit `available_balance` penjual sebesar `$sellerReleaseAmount` (`DISPUTE_PARTIAL_RELEASE`).
       2. Debit `pending_balance` penjual sebesar `$sellerClawbackPending` (`DISPUTE_PARTIAL_REFUND`) sehingga total `pending_balance` pesanan ini berkurang tepat `100\%` (`$order->seller_escrow_amount`).
       3. Kredit `available_balance` pembeli sebesar `$buyerRefundAmount` (`DISPUTE_PARTIAL_REFUND`) — **tanpa menambah `non_withdrawable_portion`**. - Simpan `dispute_buyer_refund_percent`, `dispute_buyer_refund_amount`, `dispute_seller_release_amount`, ubah status order ke `COMPLETED` (atau status final sengketa yang konsisten dengan `StateTransitionGuard`), catat `admin_audit_logs`, dan kirim notifikasi ke kedua belah pihak via `DB::afterCommit()`.  ### D. Buyer Top-Up Dompet Anti-Gestun & Cross-User Bank Blind Hash 4. **`App\Services\WalletTopUpService`** - `createTopUp(int $userId, float $amount, string$channel = 'midtrans_snap'): WalletTopUp`: Validasi min Rp 10.000, max Rp 10.000.000, buat `wallet_topups` (`status = 'UNPAID'`, `expires_at = now()->addHours(2)`).
   - `settleTopUp(string $topupCode, string$gatewayRef)`:
     - Idempoten! Kunci `wallet_topups` (`lockForUpdate()`). Jika sudah `PAID`, kembalikan langsung tanpa kredit ulang.
     - Kunci `wallets` pembeli, kredit `available_balance += $topup->amount` melalui `EscrowLedgerService` (`mutation_type = 'WALLET_TOPUP'`, `reference_code = "TOPUP-{$topup->topup_code}"`), DAN naikkan **`wallets.non_withdrawable_portion += $topup->amount`**. - Tandai `wallet_topups.status = 'PAID'`, `paid_at = now()`, `gateway_reference = $gatewayRef`.
   - **Penggerusan Otomatis Saat Belanja:** Di `EscrowLedgerService::debitBuyerAvailableForOrder(...)` (saat pembayaran checkout memakai saldo dompet), setelah mengurangi `available_balance -= $amount`, otomatis kurangi `non_withdrawable_portion = max(0, round($wallet->non_withdrawable_portion - $amount, 2))` sehingga saldo top-up dipakai duluan untuk belanja. - **Pagar Anti-Gestun Saat Withdraw:** Di `OrderStateAndSlaService::requestWithdrawal(...)`, hitung `$maxWithdrawable = max(0, $wallet->available_balance -$wallet->non_withdrawable_portion)`. Jika `$requestedAmount >$maxWithdrawable`, tolak dengan exception/pesan jelas (`AntiGestunWithdrawalException`: "Dana hasil Top-Up hanya dapat digunakan untuk berbelanja dan tidak dapat ditarik tunai. Maksimal penarikan saat ini: Rp X.").
5. **Cross-User Bank Blind Hash di `BankAccountService`**
   - Tambahkan helper `computeBankAccountBlindHash(string $bankCode, string$sanitizedAccountNumber): string` (`hash_hmac('sha256', strtoupper($bankCode).':'.$sanitizedAccountNumber, config('app.key'))`).
   - Saat `addBankAccount()`, cek apakah `active_account_lock_hash` sudah dipakai akun aktif lain -> lempar `DuplicateBankAccountException` ("Nomor rekening ini sudah terdaftar aktif pada akun NexaTrade lain.").
   - Saat `deleteBankAccount()` (soft delete), set `active_account_lock_hash = null`.

### E. Self-Service Account Recovery (Lupa Password & Lupa PIN 6-Digit)
6. **`App\Services\AccountRecoveryService`**
   - **Forgot & Reset Password (`/forgot-password`, `/reset-password/{token}`):** Menggunakan tabel standar `password_reset_tokens`, token di-hash, berlaku 30 menit, rate-limited (`auth-recovery`: 5 request/15 menit), dan invalidasi sesi lama setelah reset berhasil.
   - **Forgot & Reset Wallet PIN 6-Digit (`/account/security/pin-reset`):**
     - Memerlukan password login akun saat ini ATAU verifikasi token reset via email (`wallet_pin_reset_tokens`, berlaku 15 menit, single-use `used_at`, rate-limited 3x/15 menit).
     - Saat token valid diverifikasi bersama PIN 6-digit baru (tetap tolak PIN lemah `123456`, `000000`, `111111`): update `wallet_pin_hash`, reset `wallet_pin_attempts = 0`, `wallet_pin_locked_until = null`, tandai `used_at = now()`, dan kirim notifikasi keamanan `system`.
     - Saat `config('nexatrade.demo_switcher_enabled') === true` (lingkungan lokal tanpa SMTP), tampilkan kotak pratinjau token/link reset di flash session (tetap tersembunyi 100% di `production`!).

---

## 4. Antarmuka Light Mode & Route Utama
- **Dompet Pembeli (`/buyer/wallet`):**
  - Tampilkan rincian transparan **Saldo Aktif Total**, **Saldo Dapat Ditarik**, dan pill info **Saldo Top-Up (Khusus Belanja / Anti-Gestun)** bila `non_withdrawable_portion > 0`.
  - Tambahkan kartu **Top-Up Saldo Dompet** (pilihan nominal cepat Rp 50rb / 100rb / 250rb / 500rb / Custom + tombol "Simulasi Bayar Lunas Top-Up" pada halaman invoice top-up `/buyer/wallet/topup/{topup_code}`).
- **Meja Arbitrase Sengketa Admin (`/admin/disputes`):**
  - Tambahkan opsi ke-3 di samping "Refund 100% Pembeli" dan "Cairkan 100% Penjual": **Panel Putusan Parsial (*Split Settlement*)** dengan slider / input persentase Refund Pembeli (`1%–99%`) dan **kalkulator live Alpine.js** yang langsung memperlihatkan angka Rupiah yang diterima Pembeli vs Penjual sebelum Admin menekan eksekusi.
- **Seller Studio (`/seller/ad-credits` & `/seller/products`):**
  - Tampilkan lencana sisa masa aktif **`Featured hingga DD MMM YYYY`** pada produk yang di-boost, plus progress bar **Anti-Gaming Store Level** (menampilkan 3 syarat sekaligus: Pesanan Qualify, Pembeli Unik, dan GMV Terpenuhi).
- **Auth & Keamanan (`/forgot-password`, `/reset-password/{token}`, `/account/security`):**
  - Tambah tautan **"Lupa Password?"** di halaman `/login` dan panel **"Lupa PIN Dompet? Ajukan Reset Token"** di halaman `/account/security`.
  - Pasang `_idempotency_key` UUID tersembunyi + Alpine submit guard pada form Checkout, Withdraw, Top-Up, Beli Ad Credits, dan Pelunasan Hutang.

---

## 5. Seeder & Feature Test (`Fase5EnterprisePolishTest.php`)
- **Seeder:** Update `DatabaseSeeder.php` (non-breaking & pastikan `audit-ledger` tetap **`BALANCED`**!):
  - Isi `account_blind_hash` dan `active_account_lock_hash` pada seluruh `user_bank_accounts` seeded.
  - Seed 1 `product_boosts` aktif (berakhir `+7 hari`) dan set `featured_until` pada 1 produk Seller 1.
  - Seed 1 `wallet_topups` berstatus `PAID` untuk Buyer 1 (jika menambah saldo, wajib lewat `WalletTopUpService::settleTopUp()` atau mutasi seimbang dan **jangan mengubah ekspektasi saldo awal Buyer 1 yang dicek di test Fase 0!** Jika test Fase 0 mengecek angka eksak saldo Buyer 1, seed `wallet_topups` pada **Buyer 2** saja atau sebagai `UNPAID` agar 0 regresi!).
- **Automated Tests (`tests/Feature/Fase5EnterprisePolishTest.php` di MySQL InnoDB):**
  1. **Time-Boxed Ad Boost & Sweeper Expiry:** Seller boost produk -> `product_boosts` aktif + `featured_until` terisi; geser `ends_at` ke masa lalu lalu jalankan `php artisan nexatrade:expire-boosts` -> `product_boosts.status = 'expired'` dan `products.is_featured = false`.
  2. **Anti-Gaming Store Level Guard:** Seller menyelesaikan 30 pesanan dummy Rp 100 (atau 30 pesanan dari hanya 1 pembeli yang sama) -> `recalculateStoreLevel()` tetap menahan toko di **Level 1**; ketika memenuhi syarat minimal `>= 25` order `>= Rp 10.000`, `>= 10` pembeli unik, dan GMV `>= Rp 2.500.000` -> naik ke **Level 2**.
  3. **HTTP Idempotency Anti-Double-Click:** Kirim 2 request POST pembelian Ad Credits (dan 2 request Withdraw) berturut-turut dengan `Idempotency-Key` yang sama -> saldo hanya terpotong **1 kali** dan request kedua di-replay aman; kirim ulang key yang sama dengan payload berbeda -> ditolak **HTTP 422**.
  4. **Partial Dispute Split Settlement (60% Buyer / 40% Seller) + Anti-Farming:** Pesanan Rp 100.000 memakai Voucher Platform Rp 20.000 (`buyer_payable = 80.000`, `seller_escrow = 95.000`) disengketakan lalu diputus `SPLIT_SETTLEMENT` 60% Buyer -> Buyer hanya dapat Rp 48.000 (`60% x 80k`), Seller cair Rp 38.000 (`40% x 95k`), `pending_balance` Seller bersih turun Rp 95.000 (`0.00` tersisa), dan `nexatrade:audit-ledger` tetap **`BALANCED`**.
  5. **Buyer Wallet Top-Up & Anti-Gestun Withdraw Lock:** User top-up Rp 200.000 -> `available_balance += 200.000` & `non_withdrawable_portion += 200.000`; percobaan Withdraw ditolak (`AntiGestunWithdrawalException`); setelah belanja Rp 120.000 memakai saldo -> `non_withdrawable_portion` turun menjadi Rp 80.000; dan `audit-ledger` tetap **`BALANCED`**.
  6. **Cross-User Bank Account Anti-Tuyul:** User B mencoba mendaftarkan `bank_code + account_number` yang sama persis dengan rekening aktif User A -> ditolak (`DuplicateBankAccountException`); setelah User A menghapus rekeningnya -> User B boleh mendaftarkannya.
  7. **Self-Service Forgot Password & Reset Wallet PIN 6-Digit:** User meminta reset password dan reset PIN (saat PIN dalam kondisi terkunci 5x salah) -> token kadaluarsa/salah ditolak -> token valid berhasil mengatur PIN baru, membuka *lockout* (`wallet_pin_locked_until = null`), dan token tidak bisa dipakai ulang (`used_at !== null`).

---

## 6. Checklist Progres Eksekusi
- [x] Checkpoint 1: Buat 4 migrasi baru + migrasi aditif (ENUM aman, `non_withdrawable_portion`, `featured_until`, `active_account_lock_hash`, kolom split settlement) + model Eloquent, lalu verifikasi migrasi di MySQL InnoDB.
- [x] Checkpoint 2: Implementasikan `EnsureIdempotentRequest` middleware, `WalletTopUpService`, `AccountRecoveryService`, upgrade `AdCreditAndStoreLevelService` (+ `nexatrade:expire-boosts`), upgrade `OrderStateAndSlaService` (`SPLIT_SETTLEMENT`), dan upgrade `BankAccountService` (`account_blind_hash`).
- [x] Checkpoint 3: Bangun Light Mode UI (Top-Up & Anti-Gestun info di `/buyer/wallet`, Split Settlement slider di `/admin/disputes`, Expiry badge & 3-syarat Store Level di Seller Studio, Forgot Password & Reset PIN UI, `_idempotency_key` di semua form sensitif), update `DatabaseSeeder.php` (0 regresi saldo), dan jalankan `npm run build`.
- [x] Checkpoint 4: Tulis `tests/Feature/Fase5EnterprisePolishTest.php`, centang seluruh checklist di `docs/prd-fase-5-polish.md`, jalankan `php artisan migrate:fresh --seed`, `php artisan nexatrade:audit-ledger` (`BALANCED`), dan `php artisan test` hingga SELURUH suite (Fase 0–5) lulus 100% di `db_latata_test`.