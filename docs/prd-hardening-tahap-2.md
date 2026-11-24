# PRD Hardening Tahap 2 — Biaya MDR P&L, Async Queue (`afterCommit`) & Scheduler Heartbeat (`latata`)

## 1. Standar Arsitektur & Database (Enterprise Clean-Code Contract)
Target: Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB (`db_latata` & `db_latata_test`), Tailwind CSS v4, Alpine.js.

### Aturan Mutlak Anti-Spageti
1. **Strict Ledger Custody Separation:** Biaya MDR payment gateway adalah **beban operasional platform**, BUKAN mutasi dompet custody pengguna. Penyimpanan `gateway_mdr_fee` TIDAK BOLEH mengubah saldo `wallets` maupun `wallet_mutations`, sehingga `php artisan nexatrade:audit-ledger` wajib tetap **`[BALANCED]` (`Discrepancy = Rp 0,00`)**.
2. **Typed DTO & Backed Enums (Zero Magic Strings):** Gunakan PHP 8.3 Backed Enums (`App\Enums\WorkerHealthStatus`, `App\Enums\HeartbeatRunStatus`) dan immutable `readonly class` DTO (`App\Data\MdrCalculationResult`) untuk hasil kalkulasi MDR agar 100% bersih di PHPStan Level 8.
3. **DRY Command Wrapper:** Jangan menulis blok `try/catch` heartbeat berulang di tiap Artisan command. Sediakan helper terpusat `SchedulerHeartbeatService::track(string $commandName, string$displayName, int $expectedIntervalMinutes, callable$callback)` agar seluruh command tetap tipis dan konsisten.
4. **Thin Controllers & Zero Regression:** Seluruh 83 test eksisting wajib lulus 100%, `vendor/bin/phpstan analyse --memory-limit=1G` (Level 8, **0 errors**, nol `@phpstan-ignore`), `vendor/bin/pint --test` **PASS**, `Model::preventLazyLoading(! app()->isProduction())` tetap aktif, dan antarmuka tetap **Light Mode Anti-Slop** (nol dark slate, nol gradien ungu/indigo, nol emoji Unicode mentah — gunakan inline Lucide SVG `stroke-width="1.75"`).

---

## 2. Desain Skema Database InnoDB

### A. Tabel Baru: `scheduler_heartbeats`
- `id` BIGINT UNSIGNED PK
- `command_name` VARCHAR(100) UNIQUE (contoh: `nexatrade:audit-ledger`)
- `display_name` VARCHAR(120)
- `expected_interval_minutes` INT UNSIGNED NOT NULL
- `last_started_at` TIMESTAMP NULL
- `last_finished_at` TIMESTAMP NULL
- `last_status` ENUM(`running`, `ok`, `failed`) NOT NULL DEFAULT `ok`
- `last_duration_ms` INT UNSIGNED NULL
- `last_summary` VARCHAR(255) NULL
- `last_error` TEXT NULL
- `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0
- `created_at`, `updated_at`
- Index: `(last_status, last_finished_at)`.

### B. Migrasi Aditif Minimal pada Tabel Transaksi Gateway
- **`payment_invoices`**:
  - `gateway_payment_type` VARCHAR(40) NULL (contoh: `qris`, `bank_transfer`, `gopay`, `shopeepay`, `credit_card`, `wallet`, `simulation`)
  - `gateway_mdr_fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00
- **`wallet_topups`**:
  - `gateway_payment_type` VARCHAR(40) NULL
  - `gateway_mdr_fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00
- Pastikan tabel queue bawaan Laravel (`jobs`, `failed_jobs`) sudah ada di migrasi proyek (jangan duplikasi jika sudah tersedia di `0001_01_01_000002_create_jobs_table.php`).

---

## 3. Spesifikasi Domain Layer, Queue Jobs & Heartbeat

### A. Payment Gateway MDR Calculator & True Net Operating Profit
1. **Konfigurasi Tarif di `config/nexatrade.php` (`mdr_rates`):**
   - `qris`: `['type' => 'percent', 'rate' => 0.007, 'flat' => 0.00]` (0,7%)
   - `bank_transfer` / `virtual_account` / `echannel` / `permata` / `bca_va` / `bni_va` / `bri_va`: `['type' => 'flat', 'rate' => 0.0, 'flat' => 4000.00]` (Rp 4.000)
   - `gopay` / `shopeepay` / `ewallet`: `['type' => 'percent', 'rate' => 0.02, 'flat' => 0.00]` (2,0%)
   - `credit_card`: `['type' => 'percent_plus_flat', 'rate' => 0.029, 'flat' => 2000.00]` (2,9% + Rp 2.000)
   - `cstore`: `['type' => 'flat', 'rate' => 0.0, 'flat' => 5000.00]` (Rp 5.000)
   - `midtrans_snap` (fallback bila `payment_type` tidak dikirim): `['type' => 'flat', 'rate' => 0.0, 'flat' => 4000.00]`
   - `wallet` / `simulation`: `['type' => 'flat', 'rate' => 0.0, 'flat' => 0.00]` (Rp 0)
2. **Pure Domain Service `App\Services\PaymentMdrCalculatorService` & DTO `App\Data\MdrCalculationResult`:**
   - `calculate(string $paymentType, float$grossAmount): MdrCalculationResult` mengembalikan `normalizedChannel`, `grossAmount`, `mdrFee` (dibulatkan presisi 2 desimal `round(..., 2)`), dan `netSettlementAmount`.
   - Normalisasi alias channel (misal lowercase + trim; channel tidak dikenal jatuh ke fallback `midtrans_snap` atau `0.00` bila `grossAmount <= 0`).
3. **Pencatatan Otomatis Saat Settlement:**
   - Di `MidtransWebhookService` (baik untuk `INV-` maupun `TOPUP-`) dan `WalletTopUpService::settleTopUp()`: tangkap `payment_type` dari payload webhook/gateway (default ke channel transaksi atau `midtrans_snap`), hitung MDR via `PaymentMdrCalculatorService`, lalu simpan `gateway_payment_type` & `gateway_mdr_fee` pada baris `payment_invoices` / `wallet_topups` terkait di dalam transaksi settlement yang sama.
   - Untuk pembayaran murni saldo dompet (`wallet`), simpan `gateway_payment_type = 'wallet'` dan `gateway_mdr_fee = 0.00`.
4. **Upgrade P&L di `FinanceAndLedgerAuditService` & `/admin/finance`:**
   - Tambahkan metrik akurat pada laporan P&L:
     - `total_invoice_mdr_cost` (SUM `gateway_mdr_fee` dari `payment_invoices` berstatus `PAID`)
     - `total_topup_mdr_cost` (SUM `gateway_mdr_fee` dari `wallet_topups` berstatus `PAID`)
     - `total_gateway_mdr_cost = total_invoice_mdr_cost + total_topup_mdr_cost`
     - `true_net_operating_profit = gross_platform_revenue - total_voucher_subsidy - total_gateway_mdr_cost` (tetap pertahankan key lama di array return agar 0 regresi pada test Fase 4!).
   - Tampilkan rincian **Biaya MDR Payment Gateway** dan **True Net Operating Profit** di kartu P&L `/admin/finance` dengan `JetBrains Mono tabular-nums`.

### B. Async Queue Jobs (`DB::afterCommit`) untuk Proses Eksternal & Berat
1. **`App\Jobs\DispatchH2HOrderJob` (`implements ShouldQueue`):**
   - Properti: `public int $tries = 3;`, `public array $backoff = [10, 30, 60];`, menerima `public int $orderId`.
   - Idempoten di `handle(DigiflazzH2HService $h2hService)`: muat `Order` terbaru; jika tidak ditemukan, bukan tipe H2H, atau sudah ter-dispatch/selesai (`provider_ref_id !== null` atau status bukan `PAID_ESCROW`/`PROCESSING`), langsung return aman tanpa menembak API ulang. - Integrasi di `OrderFulfillmentDispatcher`: saat memicu fulfillment order bertipe H2H, dispatch `DispatchH2HOrderJob::dispatch($order->id)->afterCommit()`.
2. **`App\Jobs\SendUserNotificationJob` (`implements ShouldQueue`):**
   - Properti: `public int $tries = 3;`, `public array $backoff = [10, 30, 60];`.
   - Menerima payload primitif (`int $userId`, `string $category`, `string $title`, `string $body`, `?string $actionUrl = null`) sehingga aman diserialisasi ke Redis/Database queue. - Sediakan helper di `NotificationService` (atau layanan notifikasi eksisting) untuk mengirim notifikasi secara *after-commit* (`->afterCommit()`) pada jalur transaksi krusial tanpa memperlambat HTTP response. 3. **Zero Regression di `phpunit.xml`:** Karena `QUEUE_CONNECTION=sync` pada lingkungan test, seluruh 83 test lama tetap mengeksekusi job secara langsung setelah transaksi commit tanpa perubahan assertion.  ### C. Scheduler Heartbeat Monitor (Anti-Cron Mati Diam-Diam) 1. **Enums & Model:** - `App\Enums\HeartbeatRunStatus`: `RUNNING = 'running'`, `OK = 'ok'`, `FAILED = 'failed'`. - `App\Enums\WorkerHealthStatus`: `HEALTHY = 'healthy'`, `STALE = 'stale'`, `FAILED = 'failed'`, `RUNNING = 'running'`, `NEVER_RUN = 'never_run'`. - `App\Models\SchedulerHeartbeat` dengan casts Enum & datetime yang ketat (PHPStan Level 8). 2. **`App\Services\SchedulerHeartbeatService`:** - `track(string $commandName, string $displayName, int$expectedIntervalMinutes, callable $callback): mixed`: - Upsert record dengan `last_status = 'running'`, `last_started_at = now()`. - Jalankan `$callback()` sambil mengukur durasi (ms).
     - Jika sukses: simpan `last_status = 'ok'`, `last_finished_at = now()`, `last_duration_ms`, `consecutive_failures = 0`, `last_error = null`, dan `last_summary` (bila callback mengembalikan string ringkasan).
     - Jika exception: simpan `last_status = 'failed'`, `last_finished_at = now()`, increment `consecutive_failures`, simpan pesan error terpotong aman ke `last_error`, lalu **lempar ulang exception** agar exit code CLI tetap non-zero.
   - `evaluateHealth(SchedulerHeartbeat $heartbeat, ?CarbonImmutable $now = null): WorkerHealthStatus`:
     - `last_status === FAILED` -> `WorkerHealthStatus::FAILED`
     - `last_status === RUNNING` dan berjalan > `max(15, expected_interval_minutes)` menit -> `WorkerHealthStatus::STALE`, jika belum -> `WorkerHealthStatus::RUNNING`
     - `last_finished_at === null` -> `WorkerHealthStatus::NEVER_RUN`
     - `last_finished_at->diffInMinutes($now) > ($expectedIntervalMinutes * 2)` -> `WorkerHealthStatus::STALE`
     - Selain itu -> `WorkerHealthStatus::HEALTHY`.
   - `getDashboardHealthSummary(): array` (daftar seluruh worker yang terdaftar di `routes/console.php`, menggabungkan baris DB dengan registry default agar worker yang belum pernah jalan terdeteksi sebagai `NEVER_RUN`).
3. **Pasang di Seluruh Artisan Command Terjadwal (`app/Console/Commands/`):**
   - Inspeksi `routes/console.php` & semua file di `app/Console/Commands/`. Bungkus eksekusi inti setiap command terjadwal (`nexatrade:audit-ledger`, `nexatrade:reconcile-h2h`, `nexatrade:expire-boosts`, serta command SLA/auto-release escrow yang ada) memakai `SchedulerHeartbeatService::track(...)`.
4. **Light Mode UI di `/admin/finance`:**
   - Tambahkan kartu **System Worker Health (Scheduler Heartbeat)** di `/admin/finance` (tetap di bawah izin `finance.view` / `super_admin` dari RBAC Tahap 1) yang menampilkan tabel status tiap worker (Nama Worker, Interval, Terakhir Jalan, Durasi ms, Status Pill hijau `#00AA5B` / kuning `#F59E0B` / merah `#DC2626`, nol emoji).

---

## 4. Seeder & Test Suite Baru
- **Seeder:** Update `DatabaseSeeder.php` (non-breaking):
  - Isi `gateway_payment_type` & `gateway_mdr_fee` yang realistis pada invoice & topup berstatus `PAID` yang sudah di-seed (tanpa mengubah satu rupiah pun saldo `wallets` / `wallet_mutations`!).
  - Seed catatan awal `scheduler_heartbeats` berstatus `ok` agar dashboard `/admin/finance` langsung informatif.
- **Unit & Feature Tests:**
  1. **`tests/Unit/Tahap2MdrAndHeartbeatPrimitivesTest.php`:**
     - Uji `PaymentMdrCalculatorService` untuk `qris` (0,7%), `bank_transfer` (Rp 4.000), `gopay` (2%), `credit_card` (2,9% + Rp 2.000), `wallet` (Rp 0), dan fallback `midtrans_snap`.
     - Uji `SchedulerHeartbeatService::evaluateHealth()` untuk seluruh state: `HEALTHY`, `STALE` (lewat `2x interval`), `FAILED`, `RUNNING`, dan `NEVER_RUN`.
  2. **`tests/Feature/Tahap2MdrQueueAndHeartbeatTest.php` (MySQL InnoDB):**
     - Settlement invoice Midtrans (`qris` Rp 100.000 -> MDR Rp 700) dan settlement Top-Up (`bank_transfer` Rp 200.000 -> MDR Rp 4.000) menyimpan `gateway_payment_type` & `gateway_mdr_fee`, tercermin akurat di `true_net_operating_profit` pada `FinanceAndLedgerAuditService` dan tampilan `/admin/finance`, sementara `nexatrade:audit-ledger` tetap **`BALANCED`**.
     - `Queue::fake()` memverifikasi `DispatchH2HOrderJob` di-dispatch dengan `afterCommit` saat order H2H lunas (tidak ter-dispatch jika transaksi rollback), dan eksekusi ulang `handle()` bersifat idempoten.
     - Menjalankan `php artisan nexatrade:audit-ledger` dan `php artisan nexatrade:expire-boosts` memperbarui baris `scheduler_heartbeats` menjadi `ok` dengan `last_duration_ms >= 0`; memundurkan `last_finished_at` ke 3 hari lalu membuat `getDashboardHealthSummary()` menandainya `STALE` dan tampil pada `/admin/finance` tanpa memicu `LazyLoadingViolationException`.

## 5. Checklist Eksekusi Tahap 2
- [x] Checkpoint 1: Inspeksi `routes/console.php`, semua `app/Console/Commands/*`, `OrderFulfillmentDispatcher`, `MidtransWebhookService`, `FinanceAndLedgerAuditService`, dan buat migrasi `scheduler_heartbeats` + kolom aditif MDR (`payment_invoices`, `wallet_topups`), Enums, DTO `MdrCalculationResult`, dan Model `SchedulerHeartbeat`.
- [x] Checkpoint 2: Implementasikan `PaymentMdrCalculatorService`, `SchedulerHeartbeatService`, `DispatchH2HOrderJob`, `SendUserNotificationJob`, integrasikan ke webhook/topup/fulfillment/P&L dan seluruh Artisan command terjadwal.
- [x] Checkpoint 3: Perbarui UI Light Mode `/admin/finance` (rincian MDR, True Net Operating Profit, dan kartu System Worker Health), update `DatabaseSeeder.php` (0 perubahan saldo custody), dan jalankan `npm run build`.
- [x] Checkpoint 4: Tulis `Tahap2MdrAndHeartbeatPrimitivesTest.php` & `Tahap2MdrQueueAndHeartbeatTest.php`, centang checklist PRD, lalu pastikan seluruh gerbang kualitas lulus 100%: `phpstan` Level 8 (0 error), `pint --test` (PASS), `migrate:fresh --seed`, `nexatrade:audit-ledger` (`BALANCED`), dan `php artisan test` (0 failures/errors).