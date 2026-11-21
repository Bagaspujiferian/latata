# PRD Fase 3 — Growth Engine, Anti-Hackback Warranty & Admin CMS (`latata` / NexaTrade)

## 1. Tujuan & Batasan Sistem (Non-Negotiable Guardrails)
Fase 3 melengkapi ekosistem marketplace C2C NexaTrade pada **Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB (`db_latata` / `db_latata_test`), Tailwind CSS v4, dan Alpine.js** tanpa merusak satu pun fitur atau test sebelumnya.

### Aturan Mutlak
1. **Zero Regression:** Seluruh test di `NexaTradeEnterpriseEngineTest.php`, `Fase1CommerceUpgradeTest.php`, dan `Fase2TrustAndEngagementTest.php` wajib tetap lulus 100% tanpa melemahkan *assertion* lama.
2. **Non-Negative Wallet Invariant:** `wallets.pending_balance`, `wallets.available_balance`, dan `wallets.security_deposit_balance` tidak boleh pernah bernilai negatif. Jika klaim *hackback* melebihi dana penjual yang tersedia, catat selisihnya di `wallets.clawback_debt_balance` dan bekukan toko secara otomatis (`is_suspended = true`, `is_store_open = false`), sementara pembeli tetap menerima ganti rugi 100% dari Dana Jaminan Platform.
3. **Anti-Farming Subsidy Refund Law:**
   - **Voucher Toko (`issuer_type = 'seller'`):** Memotong basis perhitungan penjual (`net_basis = gross_amount - seller_discount_amount`). Komisi platform dan `seller_escrow_amount` dihitung dari `net_basis`.
   - **Voucher Platform (`issuer_type = 'platform'`):** Dialokasikan secara pro-rata ke tiap `orders` anak (sisa pembulatan ditaruh di order terakhir). Penjual tidak dirugikan (`seller_escrow_amount` tetap dihitung dari `gross_amount - seller_discount_amount`).
   - **Saat Refund (`REFUNDED`):** Penjual didebit `pending_balance` sebesar `seller_escrow_amount`, sedangkan pembeli **hanya** dikreditkan sebesar `buyer_payable_amount` (`gross - seller_discount - platform_discount`) — bukan subsidi platform. (Untuk order lama tanpa diskon di mana `buyer_payable_amount == 0`, fallback aman ke `gross_amount`).
4. **Safe ENUM Expansion:** Saat mengubah kolom ENUM (`wallet_mutations.balance_bucket`, `wallet_mutations.mutation_type`, `user_notifications.category`), periksa definisi ENUM aktual dari migrasi sebelumnya terlebih dahulu dan pertahankan seluruh nilai lama.
5. **Light Mode Anti-Slop Design System:** Patuhi estetika Light Mode Itemku/Tokopedia (`#F4F6F9` canvas, `#FFFFFF` cards, border `1px solid #E4E8EE`, `#0068E1` Trust Blue, `#00AA5B` Safe Green, `#F05A28` Urgency Orange, Plus Jakarta Sans + JetBrains Mono `tabular-nums`, `.btn-tactile`, `.card-elevate`, `.skeleton-shimmer`). Dilarang menggunakan background `slate-900`/`slate-950`, gradien `indigo/purple`, atau emoji Unicode mentah — gunakan inline Lucide SVG (`stroke-width="1.75"`).

---

## 2. Skema Database Relasional (8 Tabel Baru + Migrasi Aditif)

### Tabel Baru (MySQL 8.4 InnoDB)
1. **`vouchers`**
   - `id`, `code` VARCHAR(40) UNIQUE, `title` VARCHAR(120), `issuer_type` ENUM(`platform`, `seller`), `seller_id` (FK `seller_profiles.user_id` cascade, nullable), `game_id` (FK `games` nullOnDelete, nullable), `discount_type` ENUM(`fixed`, `percent`), `discount_value` DECIMAL(15,2), `max_discount_amount` DECIMAL(15,2) NULL, `min_purchase_amount` DECIMAL(15,2) DEFAULT 0, `quota_total` INT UNSIGNED, `quota_used` INT UNSIGNED DEFAULT 0, `max_uses_per_user` INT UNSIGNED DEFAULT 1, `starts_at` TIMESTAMP, `ends_at` TIMESTAMP, `is_active` BOOLEAN DEFAULT true, `created_by` (FK `users` cascade), timestamps.
   - Index: `(code, is_active, starts_at, ends_at)` dan `(issuer_type, seller_id, is_active)`.

2. **`voucher_usages`**
   - `id`, `voucher_id` (FK cascade), `user_id` (FK cascade), `payment_invoice_id` (FK cascade), `order_id` (FK `orders` nullOnDelete, nullable), `subsidy_bearer` ENUM(`platform`, `seller`), `discount_amount` DECIMAL(15,2), `status` ENUM(`reserved`, `applied`, `released`) DEFAULT `reserved`, timestamps.
   - Index: `(voucher_id, user_id, status)` dan `(payment_invoice_id, status)`.

3. **`flash_sales`**
   - `id`, `title` VARCHAR(140), `slug` VARCHAR(160) UNIQUE, `banner_subtitle` VARCHAR(200) NULL, `starts_at` TIMESTAMP, `ends_at` TIMESTAMP, `is_active` BOOLEAN DEFAULT true, `created_by` (FK `users` cascade), timestamps.
   - Index: `(is_active, starts_at, ends_at)`.

4. **`flash_sale_items`**
   - `id`, `flash_sale_id` (FK cascade), `product_id` (FK cascade), `flash_price` DECIMAL(15,2), `flash_quota` INT UNSIGNED, `flash_sold` INT UNSIGNED DEFAULT 0, `max_qty_per_user` INT UNSIGNED DEFAULT 1, `sort_order` TINYINT UNSIGNED DEFAULT 0, timestamps.
   - Unique: `(flash_sale_id, product_id)` | Index: `(product_id, flash_sale_id)`.

5. **`order_warranties`**
   - `id`, `order_id` (FK unique cascade), `buyer_id` (FK `users` cascade), `seller_id` (FK `seller_profiles.user_id` cascade), `warranty_days` SMALLINT UNSIGNED, `coverage_amount` DECIMAL(15,2), `starts_at` TIMESTAMP, `expires_at` TIMESTAMP, `status` ENUM(`active`, `claimed`, `refunded`, `expired`, `voided`) DEFAULT `active`, timestamps.
   - Index: `(buyer_id, status, expires_at)` dan `(seller_id, status)`.

6. **`warranty_claims`**
   - `id`, `claim_code` VARCHAR(40) UNIQUE, `order_warranty_id` (FK cascade), `order_id` (FK cascade), `buyer_id` (FK `users` cascade), `seller_id` (FK `seller_profiles.user_id` cascade), `reason_category` ENUM(`account_reclaimed_hackback`, `password_email_changed`, `banned_prior_violation`, `other`), `description` TEXT, `evidence_paths` JSON NULL (disimpan di disk privat `local`), `status` ENUM(`open`, `investigating`, `approved_refund`, `rejected`) DEFAULT `open`, `clawback_from_deposit` DECIMAL(15,2) DEFAULT 0, `clawback_from_available` DECIMAL(15,2) DEFAULT 0, `recorded_seller_debt` DECIMAL(15,2) DEFAULT 0, `admin_resolution_notes` TEXT NULL, `resolved_by` (FK `users` nullOnDelete, nullable), `resolved_at` TIMESTAMP NULL, timestamps.
   - Index: `(status, created_at)`.

7. **`moderation_reports`**
   - `id`, `reporter_id` (FK `users` cascade), `target_type` ENUM(`product`, `seller`, `review`), `target_id` BIGINT UNSIGNED, `reason_category` ENUM(`fraud_scam`, `fake_listing`, `illegal_account`, `off_platform_bypass`, `abusive_content`), `details` TEXT, `status` ENUM(`open`, `action_taken`, `dismissed`) DEFAULT `open`, `resolution_notes` VARCHAR(255) NULL, `reviewed_by` (FK `users` nullOnDelete, nullable), `reviewed_at` TIMESTAMP NULL, timestamps.
   - Index: `(status, target_type, created_at)`.

8. **`admin_audit_logs`**
   - `id`, `admin_id` (FK `users` cascade), `action` VARCHAR(80), `entity_type` VARCHAR(60), `entity_id` BIGINT UNSIGNED NULL, `before_state` JSON NULL, `after_state` JSON NULL, `ip_address` VARCHAR(45) NULL, `notes` VARCHAR(255) NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP.
   - Index: `(entity_type, entity_id, created_at)` dan `(action, created_at)`.

### Migrasi Aditif pada Tabel Eksisting (Default Aman)
- `game_categories`: tambah `escrow_hold_hours` SMALLINT UNSIGNED DEFAULT 24, `warranty_days` SMALLINT UNSIGNED DEFAULT 0.
- `seller_profiles`: tambah `is_suspended` BOOLEAN DEFAULT false, `suspension_reason` VARCHAR(255) NULL. Perbarui guard toko & checkout agar toko suspended tidak bisa menerima transaksi baru.
- `payment_invoices`: tambah `seller_discount_total` DECIMAL(15,2) DEFAULT 0, `platform_discount_total` DECIMAL(15,2) DEFAULT 0.
- `orders`: tambah `seller_discount_amount` DECIMAL(15,2) DEFAULT 0, `platform_discount_amount` DECIMAL(15,2) DEFAULT 0, `buyer_payable_amount` DECIMAL(15,2) DEFAULT 0.
- `order_items`: tambah `flash_sale_item_id` BIGINT UNSIGNED NULL (FK `flash_sale_items` nullOnDelete), `original_unit_price` DECIMAL(15,2) NULL.
- `wallets`: tambah `security_deposit_balance` DECIMAL(15,2) DEFAULT 0, `clawback_debt_balance` DECIMAL(15,2) DEFAULT 0.
- Perluas ENUM `wallet_mutations.balance_bucket` (+ `security_deposit`), `wallet_mutations.mutation_type` (+ `SECURITY_DEPOSIT_LOCK`, `WARRANTY_CLAWBACK_DEBIT`, `WARRANTY_BUYER_REFUND`, `SELLER_DEBT_RECOVERY`), dan `user_notifications.category` (+ `warranty`, `promo`, `moderation`).

---

## 3. Spesifikasi Service Layer (`app/Services/`)

1. **`VoucherAccountingService`**
   - `previewVoucherDiscount()`: Validasi kode, jendela aktif, kuota, batas per user, dan minimal belanja (terhadap item yang memenuhi syarat); kembalikan rincian diskon dan pembagian pro-rata.
   - `reserveVouchersForCheckout()`: Berjalan di dalam `DB::transaction()` milik `CheckoutSplitOrderService`; kunci `vouchers` secara ascending (`ORDER BY id ASC`, `lockForUpdate()`), cek ulang kuota di bawah lock, naikkan `quota_used`, buat `voucher_usages` (`reserved`), hitung ulang komisi & `buyer_payable_amount`.
   - `markVouchersAppliedForInvoice()` (saat `PAID`) & `releaseVouchersForExpiredInvoice()` (saat `sweepExpiredInvoices()` — kembalikan `quota_used` dan ubah status menjadi `released`).

2. **`FlashSaleService`**
   - `getActiveFlashSale()`: Ambil event flash sale aktif lengkap dengan sisa kuota.
   - `resolveAndLockFlashPriceForCheckout()`: Di dalam transaksi checkout, kunci `flash_sale_items` (`lockForUpdate()`), validasi sisa kuota dan `max_qty_per_user`, inkremen `flash_sold`, dan kembalikan snapshot harga flash + `original_unit_price`. Jika kuota habis atau batas per user tercapai, fallback aman ke harga reguler produk.
   - `releaseFlashQuotaForCancelledOrder()`: Dipanggil di `sweepExpiredInvoices()` untuk mengembalikan `flash_sold` saat invoice kedaluwarsa.

3. **`AccountWarrantyService`**
   - `issueWarrantyIfEligible(Order $order)`: Terbitkan `order_warranties` otomatis saat pesanan kategori dengan `warranty_days > 0` mencapai `DELIVERED` / `COMPLETED`. Sesuaikan juga `auto_complete_at` agar mengikuti `game_categories.escrow_hold_hours` (default 24 jam).
   - `fileWarrantyClaim()`: Kunci `order_warranties`, verifikasi milik pembeli & masih aktif sebelum `expires_at`, simpan bukti gambar di disk privat `local`, ubah status menjadi `claimed`, notifikasi Seller dan Admin.
   - `resolveWarrantyClaim()`: Kunci `warranty_claims`, `order_warranties`, serta `wallets` pembeli & penjual berurutan (`ORDER BY user_id ASC`). Pada `APPROVE_REFUND`: potong `security_deposit_balance` → potong `available_balance` → catat kekurangan ke `clawback_debt_balance` + suspend toko otomatis → kreditkan 100% `coverage_amount` ke `available_balance` pembeli → tulis `wallet_mutations` dan `admin_audit_logs`.
   - `transferAvailableToSecurityDeposit()`: Kunci dana dari `available_balance` ke `security_deposit_balance` (`SECURITY_DEPOSIT_LOCK`).

4. **`AdminCmsAndAuditService`**
   - `recordAudit()`: Catat `before_state`, `after_state`, `admin_id`, `ip_address`, dan `notes` ke `admin_audit_logs`.
   - CRUD aman untuk **Games**, **Game Categories** (komisi, `escrow_hold_hours`, `warranty_days`, `required_inputs_schema`), **Category Attributes & Options**, **Platform Vouchers**, **Flash Sales**, dan resolusi **Moderation Reports** (termasuk aksi 1-klik nonaktifkan produk / bekukan toko).

5. **Integrasi Service Eksisting**
   - `CheckoutSplitOrderService`: Terima opsional `platform_voucher_code` & `seller_voucher_codes`, terapkan `FlashSaleService`, dan simpan `buyer_payable_amount`.
   - `OrderStateAndSlaService` & `DigiflazzH2HService`: Pastikan jalur refund (`buyerClaimSlaBreachRefund`, `adminResolveDispute`, H2H gagal) mengembalikan `buyer_payable_amount` (bukan subsidi platform), dan `sweepExpiredInvoices()` melepaskan reservasi voucher serta kuota flash sale.

---

## 4. Antarmuka Light Mode & Route Utama
- **Beranda `/` & Detail `/p/{slug}`:** Strip **Flash Sale** dengan countdown + bar kuota, badge **Garansi Anti-Hackback X Hari**, dan modal **"Laporkan Produk"** (`POST /reports`).
- **Keranjang `/cart`:** Panel input **Voucher Platform** (live AJAX preview `POST /checkout/voucher-preview` dengan Light Mode shimmer) + pemilih **Voucher Toko** per kartu toko + rincian subsidi.
- **Pembeli `/buyer/orders/{code}` & `/buyer/warranties`:** Kartu **Sertifikat Garansi Anti-Hackback** + modal ajukan klaim & upload bukti privat.
- **Penjual `/seller/vouchers` & `/seller/wallet`:** CRUD **Voucher Toko**, kartu **Deposit Jaminan Anti-Hackback** + modal kunci deposit, serta banner peringatan jika ada `clawback_debt_balance`.
- **Admin CMS & Panel Kontrol:**
  - `/admin/cms/catalog`: Kelola Game, Kategori (termasuk Alpine.js Schema Builder untuk form checkout), dan Atribut Filter.
  - `/admin/cms/promos`: Kelola Voucher Platform & Event Flash Sale.
  - `/admin/warranties` (+ stream bukti privat `/admin/warranties/{claim}/evidence/{index}`): Meja putusan klaim hackback dengan preview simulasi clawback.
  - `/admin/reports`: Antrean laporan moderasi dengan aksi takedown/suspend.
  - `/admin/audit-logs`: Tabel audit trail dengan diff inspector `Before -> After`.

---

## 5. Seeder & Feature Test (`Fase3GrowthWarrantyAndCmsTest.php`)
- **Seeder:** Update `DatabaseSeeder.php` (non-breaking) dengan `escrow_hold_hours = 72` & `warranty_days = 30` pada kategori Akun, deposit jaminan Seller 1 (`250.000`) & Seller 2 (`500.000`), 3 voucher (`NEXAHEMAT20`, `NEXAPERSEN10`, `JURAGAN5K`), 1 Flash Sale aktif (3 item), 1 garansi aktif + 1 klaim `open` berbukti file lokal, 1 laporan moderasi `open`, dan 3 `admin_audit_logs`.
- **Automated Tests (`tests/Feature/Fase3GrowthWarrantyAndCmsTest.php` di MySQL InnoDB):**
  1. Akuntansi Voucher Platform + Anti-Farming Refund (pembeli bayar 80k dari barang 100k, escrow penjual 95k, saat refund pembeli hanya dapat 80k).
  2. Akuntansi Voucher Toko (komisi dan escrow dihitung dari `gross - seller_discount`).
  3. Pembagian Pro-Rata Voucher Platform pada Multi-Seller Split-Order.
  4. Flash Sale Quota Lock + Rollback Kuota Flash Sale & Reservasi Voucher saat invoice kedaluwarsa.
  5. Klaim Garansi Hackback Kasus A (deposit cukup) dan Kasus B (dana penjual kurang → saldo mentok 0.00 tanpa minus, selisih masuk `clawback_debt_balance`, toko otomatis suspended, pembeli tetap terima 100%).
  6. Admin CMS CRUD + pencatatan immutable `admin_audit_logs`.
  7. Moderation Report + 1-klik takedown produk & bukti klaim privat 403 untuk user tidak berwenang.

---

## 6. Checklist Progres Eksekusi
- [x] Checkpoint 1: Seluruh migrasi Fase 3, perluasan ENUM aman, dan model Eloquent + relasi selesai & lolos `migrate:fresh`.
- [x] Checkpoint 2: Seluruh Service (`VoucherAccountingService`, `FlashSaleService`, `AccountWarrantyService`, `AdminCmsAndAuditService`) & integrasi ke `CheckoutSplitOrderService` / `OrderStateAndSlaService` / `DigiflazzH2HService` selesai.
- [x] Checkpoint 3: Seluruh Controller, Route, Blade Views Light Mode, dan update `DatabaseSeeder.php` selesai + `npm run build` bersih.
- [x] Checkpoint 4: `Fase3GrowthWarrantyAndCmsTest.php` ditulis & seluruh suite `php artisan test` (Fase 0, 1, 2, dan 3) lulus 100% di `db_latata_test`.