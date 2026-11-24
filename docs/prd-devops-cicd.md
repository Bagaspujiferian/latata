# PRD DevOps & CI/CD — Production Docker Stack & GitHub Actions Pipeline (`latata`)

## 1. Standar Arsitektur DevOps (Enterprise Production-Ready)
Target: Laravel 13 / PHP 8.3, MySQL 8.4 InnoDB, Redis 7, Nginx, Vite (Node 22).

### Aturan Mutlak
1. **Non-Invasive ke Laragon Lokal:** Penambahan Docker & GitHub Actions TIDAK BOLEH merusak file `.env`, `phpunit.xml`, maupun alur kerja lokal Laragon (`db_latata` & `db_latata_test`).
2. **Zero Hardcoded Secrets:** Jangan pernah menaruh kredensial nyata atau `APP_KEY` produksi di `docker-compose.yml`, `Dockerfile`, atau workflow CI. Sediakan template bersih `.env.docker.example`.
3. **Full Feature Parity di Container:** Ekstensi PHP di `Dockerfile` wajib mencakup seluruh kebutuhan aktual `latata`: `pdo_mysql`, `bcmath`, `gd` (dengan freetype + jpeg + webp untuk `SecureImageUploadService` & DomPDF), `intl`, `zip`, `opcache`, `pcntl`, dan ekstensi `redis` (phpredis) agar Queue & Cache jalan di Redis 7.
4. **Quality Gates Tetap 100% Hijau:** `phpstan` Level 8 (0 errors), `pint --test` (PASS), `npm run build`, `nexatrade:audit-ledger` (`BALANCED`), dan `96/96` PHPUnit tests.

---

## 2. Spesifikasi Stack Docker Produksi

### A. `.dockerignore`
Kecualikan artefak yang memperlambat build atau membocorkan rahasia: `.git`, `.github`, `.env`, `.env.*` (kecuali `.env.docker.example`), `node_modules`, `vendor`, `storage/logs/*`, `storage/framework/cache/*`, `storage/framework/sessions/*`, `storage/framework/views/*`, `storage/app/stress_cookies/*`, `tests`, `docs`.

### B. `Dockerfile` (Multi-Stage Build, Non-Root)
1. **Stage 1 (`assets` — `node:22-alpine`):** Salin `package.json`, `package-lock.json`, `vite.config.*`, `resources/`, `public/`, jalankan `npm ci && npm run build`.
2. **Stage 2 (`vendor` — `composer:2`):** Install dependensi PHP produksi (`composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts`).
3. **Stage 3 (`app` — `php:8.3-fpm-alpine`):**
   - Install library sistem untuk GD (`freetype`, `libjpeg-turbo`, `libpng`, `libwebp`), `icu`, `libzip`, dan kompilasi ekstensi PHP (`pdo_mysql`, `bcmath`, `gd`, `intl`, `zip`, `opcache`, `pcntl`) serta `redis` (via `pecl` atau `mlocati/docker-php-extension-installer`).
   - Pasang konfigurasi PHP produksi di `docker/php/php.ini` (`memory_limit=256M`, `upload_max_filesize=12M`, `post_max_size=16M`, `expose_php=Off`) dan `docker/php/opcache.ini` (`opcache.enable=1`, `opcache.validate_timestamps=0`).
   - Salin source code aplikasi, `vendor/` dari Stage 2, dan `public/build/` dari Stage 1.
   - Pastikan hak akses `storage/` & `bootstrap/cache/` dimiliki user `www-data`, dan jalankan container sebagai non-root (`USER www-data`).
   - Sertakan entrypoint ringan `docker/entrypoint.sh` (hanya memastikan direktori storage/cache siap tanpa menjalankan `migrate:fresh` otomatis!).

### C. Konfigurasi Nginx (`docker/nginx/default.conf`)
- Root `/var/www/html/public`, `client_max_body_size 16M`, `server_tokens off`.
- Gzip aktif untuk CSS/JS/SVG/JSON, cache header jangka panjang untuk `/build/*`.
- Blokir akses file tersembunyi (`location ~ /\.(?!well-known).* { deny all; }`).
- Forward PHP ke `app:9000` dengan `fastcgi_read_timeout 60s`.
- Endpoint healthcheck ringan `/up` (atau route health bawaan Laravel 13) untuk Docker healthcheck.

### D. `docker-compose.yml` (6 Services Terisolasi)
1. **`app`**: Build dari `Dockerfile`, env dari `.env.docker` (atau variabel default aman), bergantung pada `mysql` (`service_healthy`) & `redis` (`service_healthy`).
2. **`nginx`**: `nginx:1.27-alpine`, port `${APP_PORT:-8080}:80`, mount `docker/nginx/default.conf` & aset build read-only, depends on `app`.
3. **`mysql`**: `mysql:8.4`, konfigurasi `--innodb-ft-min-token-size=3` (sinkron dengan `FulltextQuerySanitizer` Tahap 3!), named volume `latata_mysql_data`, healthcheck `mysqladmin ping`.
4. **`redis`**: `redis:7-alpine`, `--appendonly yes`, named volume `latata_redis_data`, healthcheck `redis-cli ping`.
5. **`queue`**: Menggunakan image yang sama dengan `app`, command `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600`, `restart: unless-stopped`, depends on `mysql` & `redis` healthy.
6. **`scheduler`**: Menggunakan image yang sama dengan `app`, command `php artisan schedule:work`, `restart: unless-stopped`, depends on `mysql` & `redis` healthy — menjaga seluruh worker di `scheduler_heartbeats` tetap `HEALTHY` 24/7.

### E. `.env.docker.example`
Template environment siap pakai untuk Docker: `APP_ENV=production`, `APP_DEBUG=false`, `DB_HOST=mysql`, `REDIS_HOST=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `NEXATRADE_DEMO_SWITCHER=false`, `NEXATRADE_PAYMENT_DRIVER=midtrans_sandbox`, placeholder Midtrans & Digiflazz kosong.

---

## 3. Spesifikasi GitHub Actions CI/CD (`.github/workflows/ci.yml`)
- **Trigger:** `push` ke `main` dan `pull_request` ke `main`.
- **Concurrency:** `group: ci-${{ github.ref }}`, `cancel-in-progress: true`.
- **Job `quality-and-tests` (`ubuntu-latest`):**
  - **Service Container `mysql` (`mysql:8.4`):**
    - Env: `MYSQL_ROOT_PASSWORD: ci_root_password`, `MYSQL_DATABASE: db_latata_test`.
    - Options: `--health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -pci_root_password" --health-interval=10s --health-timeout=5s --health-retries=10`.
    - Ports: `3306:3306`.
  - **Steps berurutan:**
    1. `actions/checkout@v4`
    2. `shivammathur/setup-php@v2` (`php-version: '8.3'`, extensions: `pdo_mysql, mbstring, bcmath, gd, xml, dom, intl, curl, zip`, coverage: `none`)
    3. `actions/setup-node@v4` (`node-version: '22'`, `cache: 'npm'`)
    4. Cache Composer & install (`composer install --prefer-dist --no-interaction --no-progress`)
    5. Install NPM & Build Vite (`npm ci && npm run build`)
    6. Siapkan `.env` CI dari `.env.example` + `php artisan key:generate`, arahkan koneksi DB CI ke `127.0.0.1:3306` (`db_latata_test`, `root`, `ci_root_password`).
    7. **Gate 1 — Code Style:** `vendor/bin/pint --test`
    8. **Gate 2 — Static Analysis Lv 8:** `vendor/bin/phpstan analyse --memory-limit=1G`
    9. **Gate 3 — Full Test Suite (MySQL 8.4 InnoDB):** `php artisan test --no-interaction` (memastikan `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` di-override lewat `env:` step agar konsisten dengan `phpunit.xml`!).
    10. **Gate 4 — Fresh Migration, Seeder & Global Ledger Audit:** `php artisan migrate:fresh --seed --force` diikuti `php artisan nexatrade:audit-ledger` (wajib exit code `0` & `[BALANCED]`).
- **Job `docker-build-check` (`ubuntu-latest`, berjalan paralel / setelah checkout):**
  - Validasi sintaks `docker compose config --quiet` dan uji build image `docker build --target app -t latata:ci-check .` untuk menjamin `Dockerfile` tidak pernah rusak saat ada update dependensi.

---

## 4. Checklist Eksekusi
- [ ] Checkpoint 1: Inspeksi `composer.json`, `package.json`, `phpunit.xml`, `.env.example`, dan `routes/console.php` untuk memastikan kompatibilitas variabel lingkungan antara lokal Laragon, Docker, dan GitHub Actions.
- [ ] Checkpoint 2: Buat `Dockerfile`, `.dockerignore`, `docker/php/php.ini`, `docker/php/opcache.ini`, `docker/nginx/default.conf`, `docker/entrypoint.sh`, `docker-compose.yml`, dan `.env.docker.example`.
- [ ] Checkpoint 3: Buat `.github/workflows/ci.yml` dengan MySQL 8.4 InnoDB service container, 4 quality gates, dan job validasi Docker build.
- [ ] Checkpoint 4: Validasi sintaks YAML & Dockerfile (serta `docker compose config` jika Docker CLI tersedia di mesin lokal), lalu verifikasi 0 regresi pada lingkungan lokal (`phpstan` Level 8, `pint --test`, `npm run build`, `nexatrade:audit-ledger`, dan `php artisan test` 96/96 lulus).