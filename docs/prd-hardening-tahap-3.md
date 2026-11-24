# PRD Hardening Tahap 3 — Vacation Mode (SLA-Aware), Bulk Vault Import, Buyer Cancel & MySQL FULLTEXT Search (`latata`)

## 1. Standar Arsitektur & Database (Enterprise Clean-Code Contract)
Target: Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB (`db_latata` & `db_latata_test`), Tailwind CSS v4, Alpine.js.

### Aturan Mutlak Anti-Spageti
1. **Reuse Normalized Schema (Anti-Duplikasi):** Proyek ini SUDAH memiliki model `StoreOperatingHour` (`store_operating_hours`). Inspeksi skema `store_operating_hours` dan `seller_profiles` terlebih dahulu — DILARANG menduplikasi kolom jam buka/tutup harian di `seller_profiles` jika `store_operating_hours` sudah menyediakannya.
2. **Single Responsibility & No God-Class Bloat:** DILARANG menambah ratusan baris baru ke `OrderStateAndSlaService` atau `SellerProductCrudService`. Pisahkan domain baru ke class berfokus tunggal:
   - `App\Services\SlaDeadlineCalculator` (pure domain service untuk pergeseran SLA di luar jam operasional).
   - `App\Services\VaultBulkImportService` + immutable DTO `App\Data\BulkVaultImportResult`.
   - `App\Services\ManualOrderCancellationService` (alur terima pesanan & pembatalan pre-processing oleh pembeli).
   - `App\Services\FulltextQuerySanitizer` (pure helper sanitasi operator boolean MySQL `FULLTEXT`).
3. **Strict Financial & Ledger Invariants:** Pembatalan pesanan manual oleh pembeli wajib mengikuti kunci ascending (`orders` & `wallets`), mengembalikan tepat `buyer_payable_amount` (anti-farming voucher!), mendebit `pending_balance` penjual sebesar `seller_escrow_amount`, dan mematuhi `StateTransitionGuard`. `php artisan nexatrade:audit-ledger` wajib tetap **`[BALANCED]` (`Discrepancy = Rp 0,00`)**.
4. **Quality Gates Wajib 100% Hijau:**
   - `vendor/bin/phpstan analyse --memory-limit=1G` (Level 8, **0 errors**, nol `@phpstan-ignore`).
   - `vendor/bin/pint --test` (**PASS**).
   - `Model::preventLazyLoading(! app()->isProduction())` tetap aktif (0 N+1 query).
   - Seluruh 89 test eksisting + Unit & Feature test Tahap 3 lulus 100% di `db_latata_test`.
   - **Light Mode Anti-Slop:** Nol dark slate, nol gradien indigo/ungu, nol emoji Unicode mentah (pakai inline Lucide SVG `stroke-width="1.75"`).

---

## 2. Desain Skema Database InnoDB (Minimal & Presisi)

### A. Migrasi Aditif pada `seller_profiles` (Inspeksi Kolom Eksisting Dulu!)
Tambahkan HANYA kolom yang belum ada pada `seller_profiles`:
- `is_on_vacation` BOOLEAN NOT NULL DEFAULT false
- `vacation_note` VARCHAR(255) NULL
- `operating_hours_enabled` BOOLEAN NOT NULL DEFAULT false
- `operating_timezone` VARCHAR(40) NOT NULL DEFAULT `'Asia/Jakarta'`
*(Jadwal buka/tutup per hari tetap memakai tabel ternormalisasi `store_operating_hours` yang sudah ada.)*

### B. Migrasi Aditif pada `orders`
- `seller_accepted_at` TIMESTAMP NULL (penanda bahwa penjual sudah menerima/mulai mengerjakan pesanan manual sehingga pembeli tidak bisa membatalkan sepihak lagi).
- `cancelled_by_role` VARCHAR(20) NULL (`buyer`, `seller`, `system`, `admin`) dan `cancellation_reason` VARCHAR(255) NULL (jika kolom alasan pembatalan belum ada).

### C. Migrasi Index `FULLTEXT` pada `products`
- Tambahkan index MySQL InnoDB: `$table->fullText(['title', 'description'], 'products_search_fulltext');` pada tabel `products`.

---

## 3. Spesifikasi Domain Layer & Service Architecture

### A. Vacation Mode & SLA-Aware Operating Hours
1. **Exception:** `App\Exceptions\StoreOnVacationException`.
2. **Aturan Checkout di `CheckoutSplitOrderService`:**
   - Eager-load `seller.sellerProfile` untuk seluruh item keranjang (cegah N+1!).
   - Jika toko berstatus `is_on_vacation === true`:
     - Item dengan `delivery_type === 'MANUAL'` WAJIB ditolak (`StoreOnVacationException`: "Toko sedang libur sementara untuk pesanan manual.").
     - Item dengan `delivery_type === 'INSTANT'` (Brankas otomatis) dan `'H2H'` TETAP DIIZINKAN checkout 24/7 karena berjalan otomatis tanpa kehadiran penjual.
3. **Pure Domain Service `App\Services\SlaDeadlineCalculator`:**
   - Method `calculateDeadline(CarbonImmutable $paidAt, int$slaMinutes, ?SellerProfile $sellerProfile, Collection$operatingHours): CarbonImmutable`:
     - Jika `$sellerProfile === null` atau `! $sellerProfile->operating_hours_enabled` atau `$operatingHours->isEmpty()`: kembalikan `$paidAt->addMinutes($slaMinutes)`. - Konversi `$paidAt` ke timezone toko (`$sellerProfile->operating_timezone`, fallback `Asia/Jakarta`). - Cek jadwal hari bersangkutan di `store_operating_hours`: - Jika `$paidAt` berada di dalam jam buka toko, mulai hitung dari `$paidAt` -> `$paidAt->addMinutes($slaMinutes)`. - Jika di luar jam buka (sebelum jam buka, sesudah jam tutup, atau hari libur), cari **jam buka valid terdekat berikutnya** (maksimal iterasi 8 hari agar mustahil infinite loop; jika seluruh 7 hari ditutup, fallback aman ke `$paidAt->addMinutes($slaMinutes)`), lalu tambahkan `$slaMinutes` dari jam buka tersebut dan kembalikan ke timezone aplikasi.
   - Integrasikan `SlaDeadlineCalculator` saat menentukan `sla_deadline_at` pesanan bertipe `MANUAL` yang baru lunas (`CheckoutSplitOrderService` / `MidtransWebhookService` / `OrderFulfillmentDispatcher`).

### B. Bulk Upload Stok Brankas (`product_vaults`) dengan Deduplikasi Batch
1. **Immutable DTO `App\Data\BulkVaultImportResult` (`final readonly class`):**
   - `int $totalLinesProcessed`, `int $insertedCount`, `int $skippedDuplicateInBatchCount`, `int $skippedDuplicateInDbCount`, `int $skippedEmptyCount`. 2. **Service `App\Services\VaultBulkImportService`:** - Method `importLines(int $sellerId, int $productId, array$rawLines): BulkVaultImportResult`:
     - Validasi batas maksimal **500 baris non-kosong** per transaksi (lempar `ValidationException` bila melebihi).
     - Normalisasi tiap baris (hapus UTF-8 BOM, `\r`, trim spasi); baris kosong masuk `skippedEmptyCount`.
     - Hitung `secret_blind_hash` via `VaultEncryptionService` (re-use service kriptografi eksisting—jangan duplikasi rumus hash!).
     - Saring duplikat internal di dalam batch (`skippedDuplicateInBatchCount`).
     - Jalankan **1 query** `ProductVault::whereIn('secret_blind_hash', $candidateHashes)->pluck('secret_blind_hash')` untuk menyaring kode yang sudah pernah ada di seluruh DB (`skippedDuplicateInDbCount`). - Di dalam `DB::transaction()`: kunci baris `products` milik `$sellerId` dengan `lockForUpdate()` (validasi kepemilikan + tipe `INSTANT`), enkripsi AES-256 setiap kode unik valid, insert batch ke `product_vaults` dengan status `AVAILABLE`, dan sinkronkan kolom `stock` produk secara presisi dari jumlah `AVAILABLE` aktual.
3. **FormRequest & Controller Tipis:**
   - `BulkImportVaultRequest` mendukung input textarea multiline (`vault_codes_text`) ATAU file upload `.txt`/`.csv` (maks 512KB) yang dipecah per baris dengan aman.

### C. Pembatalan Pesanan Manual oleh Pembeli (Pre-Processing Cancel)
1. **Service `App\Services\ManualOrderCancellationService`:**
   - `sellerAcceptManualOrder(int $sellerId, int$orderId): Order`:
     - Di dalam `DB::transaction()` (`lockForUpdate()` pada `orders`): validasi pemilik toko, `delivery_type === 'MANUAL'`, status `PAID_ESCROW`, dan `seller_accepted_at === null`. Set `seller_accepted_at = now()` dan kirim notifikasi after-commit ke pembeli.
   - `buyerCancelUnprocessedOrder(int $buyerId, int$orderId, string $reason): Order`: - Di dalam `DB::transaction()`: kunci baris `orders` (`lockForUpdate()`), validasi `$order->buyer_id === $buyerId`, `delivery_type === 'MANUAL'`, status masih `PAID_ESCROW`, dan `seller_accepted_at === null` (jika sudah diterima penjual / dikirim / selesai -> tolak dengan exception jelas / HTTP 422). - Validasi transisi status via `StateTransitionGuard` ke status refund/batal yang sesuai dengan skema eksisting (`REFUNDED` atau `CANCELLED` — periksa `StateTransitionGuard` & `OrderStateAndSlaService` agar 100\% konsisten dengan mekanisme SLA auto-refund!). - Kunci `wallets` pembeli & penjual secara **ascending ID**, lalu eksekusi mutasi buku besar atomik via `EscrowLedgerService`: - Debit `pending_balance` penjual tepat sebesar `seller_escrow_amount`. - Kredit `available_balance` pembeli tepat sebesar `buyer_payable_amount` (bukan harga kotor — anti-farming subsidi voucher platform!). - Kembalikan stok produk manual bila relevan, catat `cancelled_by_role = 'buyer'` & `cancellation_reason`, dan kirim notifikasi `afterCommit` ke penjual.  ### D. Upgrade Pencarian Katalog ke MySQL `FULLTEXT` Index 1. **Pure Helper `App\Services\FulltextQuerySanitizer`:** - `buildBooleanQuery(string $rawSearch): ?string`:
     - Hapus seluruh karakter operator spesial MySQL Boolean Mode (`+ - > < ( ) ~ * " @`).
     - Pecah menjadi token kata; ambil token dengan panjang `>= 3` karakter (batas default `innodb_ft_min_token_size = 3` di MySQL 8.4).
     - Jika ada token valid, susun query aman seperti `'+diamond* +mobile*'`. Jika tidak ada token `>= 3` karakter (misal user cari `"ML"`, `"FF"`, `"UC"`), kembalikan `null` agar pemanggil memakai fallback `LIKE` ter-escape.
2. **Integrasi di `ProductCatalogFilterService` / `StorefrontService`:**
   - Saat `$booleanQuery !== null`: gunakan `whereRaw('MATCH(products.title, products.description) AGAINST(? IN BOOLEAN MODE)', [$booleanQuery])` dan dukung pengurutan relevansi (`ORDER BY MATCH(...) AGAINST(...) DESC`) bila user memilih sort default saat pencarian aktif.
   - Saat `$booleanQuery === null` (kata kunci 1–2 karakter): pakai fallback `LIKE` yang aman dengan escape wildcard `%` dan `_`.

---

## 4. Antarmuka Light Mode, Seeder & Test Suite Baru
- **UI Light Mode (Zero Slop, Eager-Loaded):**
  - Seller Studio: tambahkan kartu/panel **Mode Libur (Vacation Mode) & Jam Operasional SLA** (toggle libur, catatan libur, toggle SLA jam operasional, dan editor jadwal `store_operating_hours` per hari).
  - Halaman detail produk & etalase toko: tampilkan banner info halus (amber `#F59E0B` tint terang, nol emoji) saat toko sedang libur (tetap izinkan tombol beli untuk produk INSTANT/H2H, nonaktifkan/infokan untuk produk MANUAL).
  - Seller Vault Manager: tambahkan form **Bulk Import Kode (Paste Multiline / Upload .TXT/.CSV)** yang menampilkan ringkasan hasil import (`berhasil masuk`, `duplikat dilewati`, `baris kosong`).
  - Order Detail Seller & Buyer:
    - Seller pada order MANUAL `PAID_ESCROW` (`seller_accepted_at === null`): tombol **"Mulai Proses Pesanan"** (mengunci pembatalan sepihak).
    - Buyer pada order MANUAL `PAID_ESCROW` (`seller_accepted_at === null`): tombol **"Batalkan Pesanan"** dengan input alasan singkat.
- **Test Suites Baru:**
  1. **`tests/Unit/Tahap3DomainPrimitivesTest.php`**:
     - Uji `SlaDeadlineCalculator`: order masuk di dalam jam buka, order masuk jam 02:00 dini hari bergeser ke jam buka 08:00 + SLA, order masuk di hari libur bergeser ke hari buka berikutnya, dan fallback aman saat semua hari tutup.
     - Uji `FulltextQuerySanitizer`: membersihkan karakter berbahaya `+-*()"@<>~`, menghasilkan `+token*`, dan mengembalikan `null` untuk kata pendek 2 huruf (`ML`, `UC`).
  2. **`tests/Feature/Tahap3SellerOpsAndFulltextTest.php` (MySQL InnoDB):**
     - Toko `is_on_vacation = true`: checkout produk MANUAL ditolak (`StoreOnVacationException`), sedangkan checkout produk INSTANT Vault dari toko yang sama sukses 100%; order MANUAL yang lunas di luar jam operasional mendapat `sla_deadline_at` tergeser ke jam buka berikutnya.
     - Bulk Import 10 baris kode (berisi 2 kosong, 2 duplikat internal batch, 1 duplikat sudah ada di DB) -> tepat 5 kode baru terenkripsi masuk ke `product_vaults`, stok produk bertambah 5, laporan `BulkVaultImportResult` presisi.
     - Buyer membatalkan order MANUAL yang belum diterima penjual -> refund presisi `buyer_payable_amount`, `pending_balance` penjual turun `seller_escrow_amount`, dan `audit-ledger` tetap `BALANCED`.
     - Seller menekan "Mulai Proses Pesanan" (`seller_accepted_at` terisi) -> percobaan pembatalan sepihak oleh buyer setelahnya ditolak keras.
     - Pencarian katalog dengan kata kunci panjang (`FULLTEXT`), kata kunci pendek 2 huruf (`ML`), dan input karakter spesial `+-*()"@` semuanya mengembalikan hasil akurat tanpa SQL syntax error dan tanpa `LazyLoadingViolationException`.

## 5. Checklist Eksekusi Tahap 3
- [x] Checkpoint 1: Inspeksi `StoreOperatingHour`, `SellerProfile`, `Order`, `StateTransitionGuard`, `VaultEncryptionService`, `ProductCatalogFilterService`, lalu buat migrasi minimal (`seller_profiles`, `orders`, index `FULLTEXT` `products`) + Exception & DTO `BulkVaultImportResult`.
- [x] Checkpoint 2: Implementasikan `SlaDeadlineCalculator`, `VaultBulkImportService`, `ManualOrderCancellationService`, `FulltextQuerySanitizer`, dan integrasikan ke checkout, fulfillment, serta katalog search.
- [x] Checkpoint 3: Bangun `FormRequest`, endpoint controller tipis, Light Mode UI (Vacation Mode/Jam Operasional, Bulk Vault Import, tombol Proses & Batal order manual), dan jalankan `npm run build`.
- [x] Checkpoint 4: Tulis `Tahap3DomainPrimitivesTest.php` & `Tahap3SellerOpsAndFulltextTest.php`, centang checklist PRD, lalu pastikan seluruh gerbang kualitas lulus 100%: `phpstan` Level 8 (0 error), `pint --test` (PASS), `migrate:fresh --seed`, `nexatrade:audit-ledger` (`BALANCED`), dan `php artisan test` (0 failures/errors).