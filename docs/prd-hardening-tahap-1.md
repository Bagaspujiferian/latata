# PRD Hardening Tahap 1 — Anti-Fraud Registrasi, Admin RBAC, Maker-Checker & KYC-Bank Binding (`latata`)

## 1. Standar Arsitektur & Database (Enterprise Clean-Code Contract)
Target: Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB (`db_latata` & `db_latata_test`), Tailwind CSS v4, Alpine.js.

### Aturan Mutlak Anti-Spageti
1. **Thin Controllers Only:** Controller hanya melakukan validasi (`FormRequest`), memanggil 1 method Service, dan mengembalikan View/Redirect/JSON. DILARANG menulis `DB::transaction()`, kalkulasi bisnis, atau query kompleks di dalam Controller.
2. **Single Responsibility & No God-Class Bloat:** Jangan menambah ratusan baris baru ke `OrderStateAndSlaService`. Ekstrak alur persetujuan penarikan ke `App\Services\WithdrawalApprovalService` (pertahankan delegasi tipis di method lama `OrderStateAndSlaService` demi backward compatibility).
3. **Database-Enforced Invariants:** Gunakan tipe kolom presisi, Foreign Key eksplisit (`restrictOnDelete()` untuk referensi audit/keuangan, `cascadeOnDelete()` hanya untuk child non-finansial), dan `UNIQUE` constraint di level InnoDB untuk aturan bisnis kritis.
4. **Zero Magic Strings:** Gunakan PHP 8.3 Backed Enums (`App\Enums\AdminSubrole`, `App\Enums\VerificationChannel`, `App\Enums\WithdrawalApprovalStage`) di Model casts, Service, Middleware, dan View.
5. **Quality Gates Wajib 100% Hijau:**
   - `vendor/bin/phpstan analyse --memory-limit=1G` (Level 8, **0 errors**, dilarang memakai `@phpstan-ignore` atau `ignoreErrors`).
   - `vendor/bin/pint --test` (**PASS**).
   - `Model::preventLazyLoading(! app()->isProduction())` tetap aktif (0 N+1 query).
   - `php artisan nexatrade:audit-ledger` wajib **`[BALANCED]` (`Discrepancy = Rp 0,00`)**.
   - Seluruh test eksisting + unit/feature test baru lulus 100% di `db_latata_test`.
   - **Light Mode Anti-Slop:** Nol dark slate, nol gradien indigo/ungu, nol emoji Unicode mentah (pakai inline Lucide SVG `stroke-width="1.75"`).

---

## 2. Desain Skema Database InnoDB (Normalisasi Ketat)

### A. Migrasi Tabel Baru
1. **`user_verification_challenges`** (Normalisasi Verifikasi Email & OTP HP — jangan tumpuk kolom OTP di `users`)
   - `id` BIGINT UNSIGNED PK
   - `user_id` FK -> `users.id` (`cascadeOnDelete`)
   - `channel` ENUM(`email`, `phone`)
   - `target_hash` CHAR(64) — HMAC-SHA256 dari alamat email / nomor HP ternormalisasi
   - `code_hash` CHAR(64) — hash kode OTP 6-digit / token verifikasi (TIDAK PERNAH simpan plaintext)
   - `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
   - `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5
   - `expires_at` TIMESTAMP NOT NULL
   - `consumed_at` TIMESTAMP NULL
   - `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   - Indexes: `(user_id, channel, consumed_at)` dan `(expires_at)`.

2. **`withdrawal_approvals`** (Audit Trail & Kunci Fisik Maker-Checker di Level InnoDB)
   - `id` BIGINT UNSIGNED PK
   - `withdrawal_id` FK -> `withdrawals.id` (`restrictOnDelete`)
   - `admin_id` FK -> `users.id` (`restrictOnDelete`)
   - `stage` ENUM(`maker`, `checker`)
   - `decision` ENUM(`approved`, `rejected`)
   - `notes` VARCHAR(255) NULL
   - `decided_at` TIMESTAMP NOT NULL
   - `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   - **Hard Database Constraints:**
     - `UNIQUE (withdrawal_id, stage)` — menjamin maksimal 1 Maker dan 1 Checker per penarikan.
     - `UNIQUE (withdrawal_id, admin_id)` — **menjamin secara fisik di MySQL** satu admin tidak mungkin menyetujui tahap Maker sekaligus Checker.

### B. Migrasi Aditif Minimal pada Tabel Eksisting
- **`users`**:
  - `phone_encrypted` TEXT NULL
  - `phone_blind_hash` CHAR(64) NULL UNIQUE
  - `phone_verified_at` TIMESTAMP NULL
  - `admin_subrole` ENUM(`super_admin`, `finance`, `cs_arbitrator`, `kyc_officer`) NULL (backfill otomatis ke `'super_admin'` untuk seluruh baris eksisting dengan `role = 'admin'`).
  - Wajib tambahkan `phone_encrypted` dan `phone_blind_hash` ke `$hidden` di `App\Models\User`. - **`withdrawals`**: - Inspeksi migrasi definisi kolom `withdrawals.status` terbaru terlebih dahulu! Perluas ENUM secara aman (mempertahankan seluruh nilai lama) dengan menambahkan `'MAKER_APPROVED'`.  ---  ## 3. Spesifikasi Domain Layer & Service Architecture  ### A. Anti-Bot Tuyul (Verifikasi Email & Unique Phone Blind Hash) 1. **Enums & Exceptions:** - `App\Enums\VerificationChannel`: `EMAIL = 'email'`, `PHONE = 'phone'`. - `App\Exceptions\DuplicatePhoneNumberException`, `App\Exceptions\UnverifiedAccountPromoException`. 2. **Pure Helper `App\Services\PhoneNormalizer`:** - Normalisasi nomor HP Indonesia: bersihkan spasi/tanda hubung/titik, ubah prefiks `08...` atau `+628...` menjadi format kanonik `628...`, validasi panjang `10–15` digit numerik. - `computeBlindHash(string $canonicalPhone): string` menggunakan `hash_hmac('sha256', 'phone:'.$canonicalPhone, config('app.key'))`. 3. **`App\Services\UserVerificationService`:** - `bindPhoneNumber(User $user, string $rawPhone): void`: normalisasi, cek duplikasi `phone_blind_hash` lintas user (lempar `DuplicatePhoneNumberException`), simpan `phone_encrypted` (terenkripsi Laravel `Crypt`) & `phone_blind_hash`, reset `phone_verified_at = null`. - `issueChallenge(User $user, VerificationChannel $channel): string`: batalkan/konsumsi challenge aktif sebelumnya pada channel sama, buat kode 6-digit kriptografis (`random_int`), simpan hanya `code_hash` (TTL 10 menit, `max_attempts = 5`). Rate-limited via limiter `verification-otp` (3x / 10 menit). - `verifyChallenge(User $user, VerificationChannel $channel, string$plainCode): void`: kunci baris challenge (`lockForUpdate()`), cek kedaluwarsa & batas percobaan (`attempts >= max_attempts`), bandingkan hash dengan `hash_equals()`. Jika valid: tandai `consumed_at = now()` dan isi `email_verified_at` / `phone_verified_at` pada `users`. Jika salah: increment `attempts` secara atomik.
   - Di mode lokal (`config('nexatrade.demo_switcher_enabled') === true`), controller boleh meneruskan kode pratinjau ke flash session; di `production`, kode TIDAK PERNAH bocor ke response/session.
4. **Kunci Promo Anti-Tuyul di `CheckoutSplitOrderService`:**
   - Jika checkout memakai **Voucher bersubsidi Platform** (`funded_by === 'PLATFORM'` atau ekuivalen di skema aktual) ATAU mengandung item **Flash Sale**, wajib periksa `$buyer->email_verified_at !== null &&$buyer->phone_verified_at !== null`. Jika gagal -> lempar `UnverifiedAccountPromoException` ("Verifikasi email dan nomor HP Anda terlebih dahulu untuk menggunakan Flash Sale atau Voucher Platform.").
   - **Kompatibilitas Test Lama (Wajib Audit di Tahap 0):** Periksa seluruh test Fase 0–5 & Security Patch yang membuat user dummy via `User::create()` untuk mengetes Flash Sale / Voucher Platform. Lengkapi fixture pembuatan user di test tersebut dengan `email_verified_at` & `phone_verified_at` (JANGAN melemahkan assertion atau menambah bypass flag di production code!).

### B. Granular Admin RBAC & Dual-Approval Maker-Checker
1. **Enums & Permission Matrix:**
   - `App\Enums\AdminSubrole`: `SUPER_ADMIN = 'super_admin'`, `FINANCE = 'finance'`, `CS_ARBITRATOR = 'cs_arbitrator'`, `KYC_OFFICER = 'kyc_officer'`.
   - `App\Security\AdminPermissionMatrix` (terpusat, 1 sumber kebenaran):
     - `finance.view`, `finance.audit`, `withdrawals.approve`: `super_admin`, `finance`
     - `disputes.resolve`, `warranty.resolve`, `moderation.manage`: `super_admin`, `cs_arbitrator`
     - `kyc.review`: `super_admin`, `kyc_officer`
     - `cms.manage`, `chaos.run`: `super_admin`
   - Buat middleware `App\Http\Middleware\EnsureAdminPermission` (alias `admin.perm` di `bootstrap/app.php`) dan pasang pada seluruh route grup `/admin/*` sesuai domainnya (tolak HTTP 403 untuk subrole yang tidak berwenang).
2. **`App\Services\WithdrawalApprovalService`:**
   - Konfigurasi di `config/nexatrade.php`: `'withdrawal_maker_checker_threshold' => (float) env('NEXATRADE_WITHDRAWAL_DUAL_APPROVAL_MIN', 5000000)`.
   - Di dalam `DB::transaction()` (kunci `withdrawals` dengan `lockForUpdate()`):
     - Tolak `AuthorizationException` jika `$admin->id ===$withdrawal->user_id` (Conflict of Interest) atau jika `$admin` tidak memiliki izin `withdrawals.approve`. - **Jalur Nominal Kecil (`amount < threshold`):** Langsung catat baris `withdrawal_approvals` (`stage = 'maker'`, `decision = 'approved'`), selesaikan mutasi ledger & ubah status ke `APPROVED` (1 langkah, kompatibel dengan test lama). - **Jalur Dual Approval (`amount >= threshold`):** - Jika status `PENDING`: catat `withdrawal_approvals` (`stage = 'maker'`, `decision = 'approved'`), ubah status `withdrawals` menjadi `MAKER_APPROVED`, catat `admin_audit_logs`. Saldo *locked* / *escrow* BELUM difinalisasi keluar. - Jika status `MAKER_APPROVED`: cek bahwa belum ada approval dari `$admin->id` (juga dijaga oleh `UNIQUE(withdrawal_id, admin_id)` di InnoDB!). Jika admin sama -> lempar `AuthorizationException` ("Checker wajib admin keuangan berbeda dari Maker."). Jika admin berbeda -> catat `withdrawal_approvals` (`stage = 'checker'`, `decision = 'approved'`), jalankan finalisasi ledger lewat `EscrowLedgerService`, ubah status ke `APPROVED`, catat `admin_audit_logs`, dan kirim notifikasi via `DB::afterCommit()`.
     - Pastikan juga alur `rejectWithdrawal()` dapat membatalkan penarikan dari status `PENDING` maupun `MAKER_APPROVED` dan mengembalikan saldo tertahan secara atomik (`audit-ledger` tetap `BALANCED`).

### C. Kunci Nama Rekening Bank == Nama KTP KYC (Anti-Money Mule)
1. **Pure Domain Service `App\Services\IndonesianNameMatcher`:**
   - Normalisasi ketat: lowercase, hapus gelar akademik/kehormatan umum (`dr`, `drg`, `ir`, `h`, `hj`, `drs`, `dra`, `s.kom`, `s.e`, `s.h`, `s.t`, `s.pd`, `m.kom`, `m.m`, `m.si`, `ph.d`), hapus tanda baca & spasi ganda.
   - `matches(string $kycFullName, string$bankHolderName): bool`: bandingkan token nama kanonik secara eksak (termasuk toleransi urutan/spasi wajar, tapi tolak keras nama orang berbeda).
2. **Integrasi di `BankAccountService::addBankAccount()`:**
   - Jika user memiliki `SellerKycSubmission` berstatus `APPROVED`, wajib lolos `IndonesianNameMatcher::matches($kyc->full_name,$accountHolderName)`; jika tidak -> lempar `App\Exceptions\BankNameMismatchException`.
   - Audit `DatabaseSeeder.php` & test lama agar nama KTP dan nama pemilik rekening pada fixture terverifikasi sinkron.

---

## 4. Antarmuka Light Mode & Pengujian Menyeluruh
- **UI Light Mode (Zero Slop, Eager-Loaded):**
  - `/account/security`: tambahkan kartu **Verifikasi Identitas Akun (Email & Nomor HP)** untuk mengikat nomor HP dan memasukkan kode OTP 6-digit.
  - `/admin/withdrawals` (atau panel penarikan admin aktual): eager-load `approvals.admin`, tampilkan lencana status `Menunggu Checker (Dual Approval)` beserta nama Maker Admin dan tombol eksekusi tahap 2 bagi admin berbeda.
  - Navigasi sidebar/header Admin: sembunyikan menu yang di luar izin `AdminPermissionMatrix` milik subrole aktif (tetap dikunci keras di middleware backend).
- **Test Suite Baru (Unit + Feature di MySQL InnoDB):**
  1. **`tests/Unit/Tahap1DomainPrimitivesTest.php`**: uji `PhoneNormalizer` (berbagai format `08xx`, `+628xx`, invalid) dan `IndonesianNameMatcher` (gelar, huruf besar/kecil, nama berbeda ditolak).
  2. **`tests/Feature/Tahap1AntiFraudAndRbacTest.php`**:
     - Duplikasi nomor HP lintas format (`081234567890` vs `+62 812-3456-7890`) ditolak oleh `phone_blind_hash`.
     - OTP salah berkali-kali mengunci challenge (`max_attempts`); OTP valid menandai `phone_verified_at` & `consumed_at`.
     - Buyer belum verifikasi email/HP ditolak saat checkout Flash Sale & Voucher Platform (`UnverifiedAccountPromoException`), lalu lolos begitu terverifikasi.
     - RBAC Matrix lengkap (`cs_arbitrator`, `finance`, `kyc_officer`, `super_admin`) memverifikasi HTTP 403 lintas divisi.
     - Maker-Checker Withdraw Rp 6.000.000: Finance A approve -> `MAKER_APPROVED`; Finance A coba approve tahap 2 -> ditolak 403 (0 duplikasi di `withdrawal_approvals`); Finance B approve tahap 2 -> `APPROVED`, saldo & buku besar sinkron; uji juga penolakan (`reject`) dari status `MAKER_APPROVED` mengembalikan saldo utuh.
     - KYC `APPROVED` atas nama "Budi Santoso, S.Kom." menolak rekening atas nama "Andi Wijaya" (`BankNameMismatchException`) dan menerima "BUDI SANTOSO".
     - Akhir test memverifikasi `php artisan nexatrade:audit-ledger` tetap **`[BALANCED]` (selisih Rp 0,00)**.

## 5. Checklist Eksekusi Tahap 1
- [x] Checkpoint 1: Audit skema aktual & test lama, buat 2 migrasi tabel ternormalisasi (`user_verification_challenges`, `withdrawal_approvals`) + migrasi aditif minimal (`users`, `withdrawals.status`), Backed Enums, Exceptions, dan Model relasi bergenerics PHPStan Level 8.
- [x] Checkpoint 2: Implementasikan `PhoneNormalizer`, `IndonesianNameMatcher`, `AdminPermissionMatrix`, `EnsureAdminPermission`, `UserVerificationService`, `WithdrawalApprovalService`, serta integrasi ke `CheckoutSplitOrderService`, `BankAccountService`, dan `OrderStateAndSlaService`.
- [x] Checkpoint 3: Bangun `FormRequest`, Controller tipis, Light Mode UI (`/account/security`, panel Admin Withdrawals & navigasi RBAC), perbarui `DatabaseSeeder.php` (0 regresi), dan jalankan `npm run build`.
- [x] Checkpoint 4: Tulis `Tahap1DomainPrimitivesTest.php` & `Tahap1AntiFraudAndRbacTest.php`, centang checklist PRD, lalu pastikan seluruh gerbang kualitas lulus 100%: `phpstan` Level 8 (0 error), `pint --test` (PASS), `migrate:fresh --seed`, `nexatrade:audit-ledger` (`BALANCED`), dan `php artisan test` (0 failures/errors).