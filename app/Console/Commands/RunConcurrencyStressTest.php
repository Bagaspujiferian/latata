<?php

namespace App\Console\Commands;

use App\Models\CartItem;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMutation;
use App\Services\EscrowLedgerService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\VaultEncryptionService;
use CurlHandle;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class RunConcurrencyStressTest extends Command
{
    protected $signature = 'nexatrade:stress-test
        {--url=http://latata.test : Target base URL HTTP lokal}
        {--concurrency=30 : Jumlah request HTTP serentak per skenario}
        {--scenario=all : Skenario yang dijalankan (all, vault, idempotency, flashsale)}
        {--keep-data : Jangan hapus data dummy stress_buyer_*@latata.test setelah selesai}';

    protected $description = 'Uji ketahanan konkurensi HTTP paralel (curl_multi) untuk Instant Vault SKIP LOCKED, Idempotency Race, dan Flash Sale Quota.';

    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly VaultEncryptionService $vaultService,
        private readonly FinanceAndLedgerAuditService $ledgerAuditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('DITOLAK: Command nexatrade:stress-test hanya diizinkan pada environment [local] atau [testing].');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('url'), '/');
        $concurrency = max(2, min(100, (int) $this->option('concurrency')));
        $scenarioOption = strtolower(trim((string) $this->option('scenario')));

        if (! in_array($scenarioOption, ['all', 'vault', 'idempotency', 'flashsale'], true)) {
            $this->error("Skenario tidak valid: [{$scenarioOption}]. Gunakan: all, vault, idempotency, atau flashsale.");

            return self::FAILURE;
        }

        $this->info("Memulai NexaTrade Concurrency Stress Test -> Target: {$baseUrl} | Concurrency: {$concurrency}");
        $this->cleanupStressTestData();

        $summaryRows = [];
        $failureDiagnostics = [];
        $allPassed = true;

        try {
            if (in_array($scenarioOption, ['all', 'vault'], true)) {
                $vaultResult = $this->runVaultContentionScenario($baseUrl, $concurrency);
                $summaryRows[] = $vaultResult['table_row'];
                if (! $vaultResult['passed']) {
                    $allPassed = false;
                    $failureDiagnostics[] = $vaultResult['diagnostic'];
                }
            }

            if (in_array($scenarioOption, ['all', 'idempotency'], true)) {
                $idempConcurrency = min($concurrency, 20);
                $idempResult = $this->runIdempotencyRaceScenario($baseUrl, $idempConcurrency);
                $summaryRows[] = $idempResult['table_row'];
                if (! $idempResult['passed']) {
                    $allPassed = false;
                    $failureDiagnostics[] = $idempResult['diagnostic'];
                }
            }

            if (in_array($scenarioOption, ['all', 'flashsale'], true)) {
                $flashConcurrency = min($concurrency, 25);
                $flashResult = $this->runFlashSaleContentionScenario($baseUrl, $flashConcurrency);
                $summaryRows[] = $flashResult['table_row'];
                if (! $flashResult['passed']) {
                    $allPassed = false;
                    $failureDiagnostics[] = $flashResult['diagnostic'];
                }
            }

            $auditSnapshot = $this->ledgerAuditService->runGlobalLedgerAudit('stress-test');
            $isBalanced = $auditSnapshot->status === 'BALANCED' && (float) $auditSnapshot->discrepancy_amount === 0.0;
            if (! $isBalanced) {
                $allPassed = false;
            }

            $this->newLine();
            $this->table(
                ['Skenario', 'N Req', 'Sukses', 'Ditolak Aman', 'HTTP 500/Err', 'Durasi (ms)', 'HTTP Status Histogram', 'Invarian Domain', 'Hasil'],
                $summaryRows,
            );

            foreach ($failureDiagnostics as $diag) {
                if ($diag !== '') {
                    $this->warn($diag);
                }
            }

            $custodyFormatted = number_format((float) $auditSnapshot->total_custody_wallets, 2, ',', '.');
            $ledgerFormatted = number_format((float) $auditSnapshot->total_ledger_net, 2, ',', '.');
            $discrepancyFormatted = number_format((float) $auditSnapshot->discrepancy_amount, 2, ',', '.');

            if ($isBalanced) {
                $this->info(
                    "[BALANCED] Audit {$auditSnapshot->audit_code} | Users={$auditSnapshot->users_checked} | Custody Wallets=Rp {$custodyFormatted} | Ledger Net=Rp {$ledgerFormatted} | Discrepancy=Rp {$discrepancyFormatted}"
                );
            } else {
                $this->error(
                    "[DISCREPANCY] Audit {$auditSnapshot->audit_code} | Users={$auditSnapshot->users_checked} | Custody Wallets=Rp {$custodyFormatted} | Ledger Net=Rp {$ledgerFormatted} | Discrepancy=Rp {$discrepancyFormatted}"
                );
            }
        } finally {
            if (! (bool) $this->option('keep-data')) {
                $this->cleanupStressTestData();
            }
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Skenario A: 1 produk instant_vault dengan 5 stok brankas diserbu N buyer serentak.
     *
     * @return array{
     *     passed: bool,
     *     table_row: list<string>,
     *     diagnostic: string
     * }
     */
    private function runVaultContentionScenario(string $baseUrl, int $concurrency): array
    {
        $vaultStock = 5;
        $fixture = $this->prepareVaultContentionScenario($concurrency, $vaultStock);
        $product = $fixture['product'];
        $buyers = $fixture['buyers'];

        $this->line(" -> [Skenario A] Menyiapkan {$concurrency} sesi buyer untuk Instant Vault Contention (Stok={$vaultStock})...");

        $requests = [];
        foreach ($buyers as $idx => $buyer) {
            $session = $this->mintAuthenticatedBuyerSession($buyer);

            $requests[$idx] = [
                'url' => "{$baseUrl}/checkout",
                'cookie_header' => $session['cookie_header'],
                'headers' => [
                    'Accept: application/json',
                    'X-CSRF-TOKEN: '.$session['csrf_token'],
                ],
                'post_fields' => http_build_query([
                    '_token' => $session['csrf_token'],
                    'payment_mode' => 'wallet_full',
                    'payment_channel' => 'wallet_full',
                ]),
            ];
        }

        $this->line(" -> [Skenario A] Menembakkan {$concurrency} request POST /checkout serentak (curl_multi)...");
        $burst = $this->fireParallelPostRequests($requests);
        $eval = $this->evaluateVaultScenario($product->id, $vaultStock);

        $passed = $eval['passed'] && $burst['server_error_count'] === 0 && $burst['success_count'] === $vaultStock;
        $diagnostic = $passed
            ? ''
            : "[DIAGNOSTIK Skenario A] Histogram=[{$burst['histogram']}] | Cuplikan Error Pertama: {$burst['first_error_sample']}";

        return [
            'passed' => $passed,
            'table_row' => [
                'A. Instant Vault SKIP LOCKED',
                (string) $concurrency,
                (string) $burst['success_count'],
                (string) $burst['rejected_count'],
                (string) $burst['server_error_count'],
                (string) $burst['duration_ms'],
                $burst['histogram'],
                "Orders={$eval['paid_orders_count']}/{$vaultStock}, UniqueVaults={$eval['unique_vault_ids_count']}/{$vaultStock}, Stock={$eval['remaining_stock']}",
                $passed ? 'PASS' : 'FAIL',
            ],
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * Skenario B: 1 buyer menembakkan N request checkout identik serentak dengan Idempotency-Key yang sama.
     *
     * @return array{
     *     passed: bool,
     *     table_row: list<string>,
     *     diagnostic: string
     * }
     */
    private function runIdempotencyRaceScenario(string $baseUrl, int $concurrency): array
    {
        $fixture = $this->prepareIdempotencyRaceScenario();
        $buyer = $fixture['buyer'];
        $idempotencyKey = $fixture['idempotency_key'];

        $this->line(" -> [Skenario B] Menyiapkan {$concurrency} koneksi paralel buyer tunggal untuk HTTP Idempotency Race (Key={$idempotencyKey})...");

        $requests = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $session = $this->mintAuthenticatedBuyerSession($buyer);

            $requests[$i] = [
                'url' => "{$baseUrl}/checkout",
                'cookie_header' => $session['cookie_header'],
                'headers' => [
                    'Accept: application/json',
                    'X-CSRF-TOKEN: '.$session['csrf_token'],
                    'Idempotency-Key: '.$idempotencyKey,
                ],
                'post_fields' => http_build_query([
                    '_token' => $session['csrf_token'],
                    'payment_mode' => 'wallet_full',
                    'payment_channel' => 'wallet_full',
                ]),
            ];
        }

        $this->line(" -> [Skenario B] Menembakkan {$concurrency} request identik serentak (curl_multi)...");
        $burst = $this->fireParallelPostRequests($requests);
        $eval = $this->evaluateIdempotencyScenario($buyer->id, $fixture['initial_balance'], $fixture['unit_price']);

        $passed = $eval['passed'] && $burst['server_error_count'] === 0;
        $diagnostic = $passed
            ? ''
            : "[DIAGNOSTIK Skenario B] Histogram=[{$burst['histogram']}] | Cuplikan Error Pertama: {$burst['first_error_sample']}";

        return [
            'passed' => $passed,
            'table_row' => [
                'B. HTTP Idempotency Race',
                (string) $concurrency,
                (string) $eval['paid_invoices_count'],
                (string) ($concurrency - $eval['paid_invoices_count'] - $burst['server_error_count']),
                (string) $burst['server_error_count'],
                (string) $burst['duration_ms'],
                $burst['histogram'],
                "PaidInvoices={$eval['paid_invoices_count']}/1, WalletDebits={$eval['wallet_debits_count']}/1",
                $passed ? 'PASS' : 'FAIL',
            ],
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * Skenario C: 1 produk Flash Sale (kuota 3 unit) diserbu N buyer berbeda secara serentak.
     *
     * @return array{
     *     passed: bool,
     *     table_row: list<string>,
     *     diagnostic: string
     * }
     */
    private function runFlashSaleContentionScenario(string $baseUrl, int $concurrency): array
    {
        $flashQuota = 3;
        $fixture = $this->prepareFlashSaleContentionScenario($concurrency, $flashQuota);
        $flashSaleItem = $fixture['flash_sale_item'];
        $buyers = $fixture['buyers'];
        $flashPrice = $fixture['flash_price'];

        $this->line(" -> [Skenario C] Menyiapkan {$concurrency} sesi buyer untuk Flash Sale Quota Contention (Kuota={$flashQuota})...");

        $requests = [];
        foreach ($buyers as $idx => $buyer) {
            $session = $this->mintAuthenticatedBuyerSession($buyer);

            $requests[$idx] = [
                'url' => "{$baseUrl}/checkout",
                'cookie_header' => $session['cookie_header'],
                'headers' => [
                    'Accept: application/json',
                    'X-CSRF-TOKEN: '.$session['csrf_token'],
                ],
                'post_fields' => http_build_query([
                    '_token' => $session['csrf_token'],
                    'payment_mode' => 'wallet_full',
                    'payment_channel' => 'wallet_full',
                    'expected_grand_total' => $flashPrice,
                ]),
            ];
        }

        $this->line(" -> [Skenario C] Menembakkan {$concurrency} request Flash Sale serentak (curl_multi)...");
        $burst = $this->fireParallelPostRequests($requests);
        $eval = $this->evaluateFlashSaleScenario($flashSaleItem->id, $flashQuota);

        $passed = $eval['passed'] && $burst['server_error_count'] === 0 && $burst['success_count'] === $flashQuota;
        $diagnostic = $passed
            ? ''
            : "[DIAGNOSTIK Skenario C] Histogram=[{$burst['histogram']}] | Cuplikan Error Pertama: {$burst['first_error_sample']}";

        return [
            'passed' => $passed,
            'table_row' => [
                'C. Flash Sale Quota Contention',
                (string) $concurrency,
                (string) $burst['success_count'],
                (string) $burst['rejected_count'],
                (string) $burst['server_error_count'],
                (string) $burst['duration_ms'],
                $burst['histogram'],
                "FlashSold={$eval['flash_sold']}/{$flashQuota}, PromoItems={$eval['promo_order_items_count']}/{$flashQuota}",
                $passed ? 'PASS' : 'FAIL',
            ],
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * Prepare Scenario A database fixtures: 1 instant_vault product with exact $vaultStock rows and $buyerCount buyers.
     *
     * @return array{
     *     seller: User,
     *     product: Product,
     *     buyers: list<User>
     * }
     */
    public function prepareVaultContentionScenario(int $buyerCount = 30, int $vaultStock = 5): array
    {
        [$seller, $category] = $this->ensureStressSellerAndCategory();
        $runTag = strtoupper(Str::random(6));

        $product = Product::create([
            'seller_id' => $seller->id,
            'game_category_id' => $category->id,
            'title' => "STRESS Vault Product {$runTag}",
            'slug' => 'stress-vault-product-'.strtolower($runTag),
            'sku_code' => "STRESS-SKU-VAULT-{$runTag}",
            'description' => 'Produk uji konkurensi brankas instan FOR UPDATE SKIP LOCKED.',
            'price' => '20000.00',
            'stock' => $vaultStock,
            'min_order' => 1,
            'max_order' => 1,
            'sla_target_minutes' => 5,
            'is_active' => true,
        ]);

        for ($v = 1; $v <= $vaultStock; $v++) {
            $rawSecret = "STRESS-VAULT-SECRET-{$runTag}-ITEM-{$v}";
            ProductVault::create([
                'product_id' => $product->id,
                'encrypted_payload' => Crypt::encryptString($rawSecret),
                'payload_blind_hash' => $this->vaultService->computeBlindHash($rawSecret),
                'status' => 'available',
            ]);
        }

        $buyers = $this->createStressBuyers(
            prefix: 'vault',
            count: $buyerCount,
            initialBalance: 100000.00,
            productId: $product->id,
        );

        return [
            'seller' => $seller,
            'product' => $product,
            'buyers' => $buyers,
        ];
    }

    /**
     * Evaluate Scenario A invariants against database state.
     *
     * @return array{
     *     passed: bool,
     *     paid_orders_count: int,
     *     unique_vault_ids_count: int,
     *     sold_vaults_count: int,
     *     available_vaults_count: int,
     *     remaining_stock: int
     * }
     */
    public function evaluateVaultScenario(int $productId, int $expectedStock = 5): array
    {
        $product = Product::findOrFail($productId);

        $orderItems = OrderItem::where('product_id', $productId)
            ->whereHas('order', function ($q): void {
                $q->whereIn('status', ['PAID_ESCROW', 'PROCESSING', 'DELIVERED', 'COMPLETED']);
            })
            ->get();

        $paidOrdersCount = $orderItems->pluck('order_id')->unique()->count();
        $allocatedVaultIds = $orderItems->pluck('product_vault_id')->filter()->values()->all();
        $uniqueVaultIdsCount = count(array_unique($allocatedVaultIds));

        $soldVaultsCount = ProductVault::where('product_id', $productId)->where('status', 'sold')->count();
        $availableVaultsCount = ProductVault::where('product_id', $productId)->where('status', 'available')->count();
        $remainingStock = (int) $product->stock;

        $passed = $paidOrdersCount === $expectedStock
            && count($allocatedVaultIds) === $expectedStock
            && $uniqueVaultIdsCount === $expectedStock
            && $soldVaultsCount === $expectedStock
            && $availableVaultsCount === 0
            && $remainingStock === 0;

        return [
            'passed' => $passed,
            'paid_orders_count' => $paidOrdersCount,
            'unique_vault_ids_count' => $uniqueVaultIdsCount,
            'sold_vaults_count' => $soldVaultsCount,
            'available_vaults_count' => $availableVaultsCount,
            'remaining_stock' => $remainingStock,
        ];
    }

    /**
     * Prepare Scenario B database fixtures: 1 buyer with sufficient balance and 1 shared Idempotency-Key.
     *
     * @return array{
     *     seller: User,
     *     product: Product,
     *     buyer: User,
     *     idempotency_key: string,
     *     initial_balance: float,
     *     unit_price: float
     * }
     */
    public function prepareIdempotencyRaceScenario(): array
    {
        [$seller, $category] = $this->ensureStressSellerAndCategory();
        $runTag = strtoupper(Str::random(6));
        $unitPrice = 25000.00;
        $initialBalance = 500000.00;

        $product = Product::create([
            'seller_id' => $seller->id,
            'game_category_id' => $category->id,
            'title' => "STRESS Idempotency Product {$runTag}",
            'slug' => 'stress-idemp-product-'.strtolower($runTag),
            'sku_code' => "STRESS-SKU-IDEMP-{$runTag}",
            'description' => 'Produk uji konkurensi HTTP Idempotency-Key.',
            'price' => number_format($unitPrice, 2, '.', ''),
            'stock' => 25,
            'min_order' => 1,
            'max_order' => 1,
            'sla_target_minutes' => 5,
            'is_active' => true,
        ]);

        for ($v = 1; $v <= 25; $v++) {
            $rawSecret = "STRESS-IDEMP-SECRET-{$runTag}-ITEM-{$v}";
            ProductVault::create([
                'product_id' => $product->id,
                'encrypted_payload' => Crypt::encryptString($rawSecret),
                'payload_blind_hash' => $this->vaultService->computeBlindHash($rawSecret),
                'status' => 'available',
            ]);
        }

        $buyers = $this->createStressBuyers(
            prefix: 'idemp',
            count: 1,
            initialBalance: $initialBalance,
            productId: $product->id,
        );

        return [
            'seller' => $seller,
            'product' => $product,
            'buyer' => $buyers[0],
            'idempotency_key' => "STRESS-IDEMP-KEY-{$runTag}",
            'initial_balance' => $initialBalance,
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * Evaluate Scenario B invariants against database state.
     *
     * @return array{
     *     passed: bool,
     *     paid_invoices_count: int,
     *     wallet_debits_count: int,
     *     expected_balance: float,
     *     actual_balance: float
     * }
     */
    public function evaluateIdempotencyScenario(int $buyerId, float $initialBalance = 500000.00, float $unitPrice = 25000.00): array
    {
        $paidInvoicesCount = PaymentInvoice::where('buyer_id', $buyerId)
            ->where('status', 'PAID')
            ->count();

        $walletDebitsCount = WalletMutation::where('user_id', $buyerId)
            ->where('mutation_type', 'PAY_ORDER_FULL_WALLET')
            ->where('direction', 'DEBIT')
            ->count();

        $wallet = Wallet::where('user_id', $buyerId)->firstOrFail();
        $expectedBalance = round($initialBalance - $unitPrice, 2);
        $actualBalance = round((float) $wallet->available_balance, 2);

        $passed = $paidInvoicesCount === 1
            && $walletDebitsCount === 1
            && abs($actualBalance - $expectedBalance) < 0.01;

        return [
            'passed' => $passed,
            'paid_invoices_count' => $paidInvoicesCount,
            'wallet_debits_count' => $walletDebitsCount,
            'expected_balance' => $expectedBalance,
            'actual_balance' => $actualBalance,
        ];
    }

    /**
     * Prepare Scenario C database fixtures: 1 Flash Sale product with quota = $flashQuota and $buyerCount verified buyers.
     *
     * @return array{
     *     seller: User,
     *     product: Product,
     *     flash_sale: FlashSale,
     *     flash_sale_item: FlashSaleItem,
     *     buyers: list<User>,
     *     flash_price: float
     * }
     */
    public function prepareFlashSaleContentionScenario(int $buyerCount = 25, int $flashQuota = 3): array
    {
        [$seller, $category] = $this->ensureStressSellerAndCategory();
        $runTag = strtoupper(Str::random(6));
        $regularPrice = 100000.00;
        $flashPrice = 50000.00;
        $totalStock = max(30, $buyerCount + 5);

        $product = Product::create([
            'seller_id' => $seller->id,
            'game_category_id' => $category->id,
            'title' => "STRESS FlashSale Product {$runTag}",
            'slug' => 'stress-flashsale-product-'.strtolower($runTag),
            'sku_code' => "STRESS-SKU-FLASH-{$runTag}",
            'description' => 'Produk uji konkurensi kuota Flash Sale.',
            'price' => number_format($regularPrice, 2, '.', ''),
            'stock' => $totalStock,
            'min_order' => 1,
            'max_order' => 1,
            'sla_target_minutes' => 5,
            'is_active' => true,
        ]);

        for ($v = 1; $v <= $totalStock; $v++) {
            $rawSecret = "STRESS-FLASH-SECRET-{$runTag}-ITEM-{$v}";
            ProductVault::create([
                'product_id' => $product->id,
                'encrypted_payload' => Crypt::encryptString($rawSecret),
                'payload_blind_hash' => $this->vaultService->computeBlindHash($rawSecret),
                'status' => 'available',
            ]);
        }

        // Deactivate any other overlapping stress flash sales so getActiveFlashSale picks this one deterministically
        FlashSale::where('title', 'like', 'STRESS-FLASHSALE-%')->update(['is_active' => false]);

        $flashSale = FlashSale::create([
            'title' => "STRESS-FLASHSALE-{$runTag}",
            'slug' => 'stress-flashsale-'.strtolower($runTag),
            'banner_subtitle' => 'Stress Test Flash Sale Contention',
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addMinutes(60),
            'is_active' => true,
            'created_by' => $seller->id,
        ]);

        $flashSaleItem = FlashSaleItem::create([
            'flash_sale_id' => $flashSale->id,
            'product_id' => $product->id,
            'flash_price' => number_format($flashPrice, 2, '.', ''),
            'flash_quota' => $flashQuota,
            'flash_sold' => 0,
            'max_qty_per_user' => 1,
            'sort_order' => 1,
        ]);

        $buyers = $this->createStressBuyers(
            prefix: 'flash',
            count: $buyerCount,
            initialBalance: 150000.00,
            productId: $product->id,
        );

        return [
            'seller' => $seller,
            'product' => $product,
            'flash_sale' => $flashSale,
            'flash_sale_item' => $flashSaleItem,
            'buyers' => $buyers,
            'flash_price' => $flashPrice,
        ];
    }

    /**
     * Evaluate Scenario C invariants against database state.
     *
     * @return array{
     *     passed: bool,
     *     flash_sold: int,
     *     promo_order_items_count: int
     * }
     */
    public function evaluateFlashSaleScenario(int $flashSaleItemId, int $expectedQuota = 3): array
    {
        $item = FlashSaleItem::findOrFail($flashSaleItemId);
        $flashSold = (int) $item->flash_sold;

        $promoOrderItemsCount = OrderItem::where('flash_sale_item_id', $flashSaleItemId)
            ->whereHas('order', function ($q): void {
                $q->whereIn('status', ['PAID_ESCROW', 'PROCESSING', 'DELIVERED', 'COMPLETED']);
            })
            ->count();

        $passed = $flashSold === $expectedQuota && $promoOrderItemsCount === $expectedQuota;

        return [
            'passed' => $passed,
            'flash_sold' => $flashSold,
            'promo_order_items_count' => $promoOrderItemsCount,
        ];
    }

    /**
     * Clean up all stress-test dummy records and temporary cookie jars.
     */
    public function cleanupStressTestData(): void
    {
        DB::transaction(function (): void {
            $stressUserIds = User::where('email', 'like', 'stress_%@latata.test')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $stressProductIds = Product::withTrashed()
                ->where('sku_code', 'like', 'STRESS-SKU-%')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $stressOrderIds = Order::whereIn('buyer_id', $stressUserIds)
                ->orWhereIn('seller_id', $stressUserIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $stressInvoiceIds = PaymentInvoice::whereIn('buyer_id', $stressUserIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (! empty($stressOrderIds)) {
                DB::table('dispute_messages')->whereIn('order_id', $stressOrderIds)->delete();
                DB::table('order_warranties')->whereIn('order_id', $stressOrderIds)->delete();
                DB::table('wallet_mutations')->whereIn('order_id', $stressOrderIds)->delete();
                DB::table('order_items')->whereIn('order_id', $stressOrderIds)->delete();
            }

            if (! empty($stressInvoiceIds)) {
                DB::table('wallet_mutations')->whereIn('payment_invoice_id', $stressInvoiceIds)->delete();
            }

            if (! empty($stressProductIds)) {
                DB::table('order_items')->whereIn('product_id', $stressProductIds)->delete();
                DB::table('product_vaults')->whereIn('product_id', $stressProductIds)->delete();
                DB::table('flash_sale_items')->whereIn('product_id', $stressProductIds)->delete();
                DB::table('cart_items')->whereIn('product_id', $stressProductIds)->delete();
            }

            if (! empty($stressOrderIds)) {
                DB::table('orders')->whereIn('id', $stressOrderIds)->delete();
            }

            if (! empty($stressInvoiceIds)) {
                DB::table('payment_invoices')->whereIn('id', $stressInvoiceIds)->delete();
            }

            DB::table('flash_sales')->where('title', 'like', 'STRESS-FLASHSALE-%')->delete();

            if (! empty($stressProductIds)) {
                DB::table('products')->whereIn('id', $stressProductIds)->delete();
            }

            if (! empty($stressUserIds)) {
                DB::table('user_notifications')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('cart_items')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('idempotency_keys')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('wallet_mutations')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('seller_profiles')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('wallets')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('sessions')->whereIn('user_id', $stressUserIds)->delete();
                DB::table('users')->whereIn('id', $stressUserIds)->delete();
            }
        });

        $cookieDir = storage_path('app/stress_cookies');
        if (File::isDirectory($cookieDir)) {
            File::cleanDirectory($cookieDir);
        }
    }

    /**
     * Ensure dedicated stress-test seller and instant_vault category exist.
     *
     * @return array{0: User, 1: GameCategory}
     */
    private function ensureStressSellerAndCategory(): array
    {
        $seller = User::firstOrCreate(
            ['email' => 'stress_seller@latata.test'],
            [
                'name' => 'Stress Test Seller',
                'password' => Hash::make('password'),
                'role' => 'seller',
                'phone' => '081199990000',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
                'is_banned' => false,
            ],
        );

        Wallet::firstOrCreate(
            ['user_id' => $seller->id],
            [
                'pending_balance' => '0.00',
                'available_balance' => '0.00',
                'non_withdrawable_portion' => '0.00',
                'security_deposit_balance' => '0.00',
                'clawback_debt_balance' => '0.00',
                'ad_credit_balance' => 0,
            ],
        );

        SellerProfile::firstOrCreate(
            ['user_id' => $seller->id],
            [
                'store_name' => 'Stress Test Official Store',
                'slug' => 'stress-test-official-store',
                'store_bio' => 'Toko khusus pengujian konkurensi NexaTrade.',
                'is_store_open' => true,
                'auto_schedule_enabled' => false,
                'operating_hours_enabled' => false,
                'is_on_vacation' => false,
                'is_suspended' => false,
                'store_level' => 2,
            ],
        );

        $category = GameCategory::where('delivery_method', 'instant_vault')->first();
        if (! $category) {
            $game = Game::firstOrCreate(
                ['slug' => 'stress-test-game'],
                [
                    'name' => 'Stress Test Game',
                    'publisher' => 'NexaTrade QA',
                    'is_active' => true,
                ],
            );

            $category = GameCategory::create([
                'game_id' => $game->id,
                'name' => 'Stress Test Instant Vault',
                'slug' => 'stress-test-instant-vault',
                'delivery_method' => 'instant_vault',
                'platform_fee_percent' => '5.00',
                'escrow_hold_hours' => 24,
                'warranty_days' => 0,
                'required_inputs_schema' => [],
            ]);
        }

        return [$seller, $category];
    }

    /**
     * Create $count stress buyers with double-entry ledger initial balance and pre-populated cart item.
     *
     * @return list<User>
     */
    private function createStressBuyers(string $prefix, int $count, float $initialBalance, int $productId): array
    {
        $passwordHash = Hash::make('password');
        $buyers = [];
        $now = now();

        for ($i = 1; $i <= $count; $i++) {
            $email = "stress_buyer_{$prefix}_{$i}@latata.test";
            $phone = sprintf('0819%08d', random_int(10000000, 99999999));

            $buyer = User::create([
                'name' => "Stress Buyer {$prefix} #{$i}",
                'email' => $email,
                'password' => $passwordHash,
                'role' => 'buyer',
                'phone' => $phone,
                'email_verified_at' => $now,
                'phone_verified_at' => $now,
                'is_active' => true,
                'is_banned' => false,
            ]);

            Wallet::create([
                'user_id' => $buyer->id,
                'pending_balance' => '0.00',
                'available_balance' => '0.00',
                'non_withdrawable_portion' => '0.00',
                'security_deposit_balance' => '0.00',
                'clawback_debt_balance' => '0.00',
                'ad_credit_balance' => 0,
            ]);

            $this->ledgerService->adminDemoTopup(
                userId: $buyer->id,
                amount: $initialBalance,
                referenceCode: 'STRESS-TOPUP-'.strtoupper($prefix)."-{$buyer->id}-".strtoupper(Str::random(5)),
                description: "Saldo awal stress test ({$email})",
            );

            CartItem::create([
                'user_id' => $buyer->id,
                'product_id' => $productId,
                'quantity' => 1,
                'buyer_game_inputs' => null,
            ]);

            $buyers[] = $buyer;
        }

        return $buyers;
    }

    /**
     * Mint a valid authenticated Laravel web session & encrypted session cookie for a stress-test buyer
     * without hitting HTTP login throttle or libcurl cookie-jar disk flushing race conditions.
     *
     * @return array{cookie_header: non-empty-string, csrf_token: string, session_id: string}
     */
    public function mintAuthenticatedBuyerSession(User $buyer): array
    {
        $sessionId = Str::random(40);
        $csrfToken = Str::random(40);
        $guardName = Auth::guard('web')->getName();

        $attributes = [
            '_token' => $csrfToken,
            $guardName => $buyer->id,
            '_flash' => [
                'old' => [],
                'new' => [],
            ],
        ];

        $sessionPayload = config('session.serialization', 'php') === 'json'
            ? (string) json_encode($attributes, JSON_INVALID_UTF8_SUBSTITUTE)
            : serialize($attributes);

        if ((string) config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->insert([
                'id' => $sessionId,
                'user_id' => $buyer->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'NexaTrade-ConcurrencyStress/1.0',
                'payload' => base64_encode($sessionPayload),
                'last_activity' => now()->getTimestamp(),
            ]);
        } else {
            Session::driver()->getHandler()->write($sessionId, $sessionPayload);
        }

        $cookieName = (string) config('session.cookie');
        $encrypter = app('encrypter');
        $cookiePrefix = CookieValuePrefix::create($cookieName, $encrypter->getKey());
        $encryptedSessionCookie = $encrypter->encrypt($cookiePrefix.$sessionId, false);
        $cookieHeader = ($cookieName !== '' ? $cookieName : 'laravel-session').'='.rawurlencode($encryptedSessionCookie);

        return [
            'cookie_header' => $cookieHeader,
            'csrf_token' => $csrfToken,
            'session_id' => $sessionId,
        ];
    }

    /**
     * Execute all prepared POST requests concurrently in a single curl_multi event loop.
     *
     * @param  array<int, array{url: string, cookie_header: non-empty-string, headers: list<string>, post_fields: string}>  $requests
     * @return array{
     *     success_count: int,
     *     rejected_count: int,
     *     server_error_count: int,
     *     duration_ms: int,
     *     status_codes: array<int, int>,
     *     histogram: string,
     *     first_error_sample: string
     * }
     */
    private function fireParallelPostRequests(array $requests): array
    {
        $mh = curl_multi_init();
        /** @var array<int, CurlHandle> $handles */
        $handles = [];

        foreach ($requests as $idx => $req) {
            $ch = curl_init($req['url']);
            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_POST => true,
                CURLOPT_COOKIE => $req['cookie_header'],
                CURLOPT_HTTPHEADER => $req['headers'],
                CURLOPT_POSTFIELDS => $req['post_fields'],
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        $startedAt = hrtime(true);
        $this->drainCurlMulti($mh);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $successCount = 0;
        $rejectedCount = 0;
        $serverErrorCount = 0;
        $statusCodes = [];
        /** @var array<int, int> $histogramCounts */
        $histogramCounts = [
            200 => 0,
            302 => 0,
            409 => 0,
            419 => 0,
            422 => 0,
            429 => 0,
            500 => 0,
            0 => 0,
        ];
        $firstErrorSample = 'N/A';

        foreach ($handles as $idx => $ch) {
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError = curl_error($ch);
            $rawResponse = (string) curl_multi_getcontent($ch);
            $rawHeaders = substr($rawResponse, 0, $headerSize);
            $rawBody = trim(substr($rawResponse, $headerSize));

            $isReplayed = stripos($rawHeaders, 'X-Idempotency-Replayed: true') !== false;
            $isValidCheckoutRedirect = $statusCode === 302
                && (stripos($rawHeaders, '/buyer/orders') !== false || stripos($rawHeaders, '/invoices/') !== false);

            $statusCodes[$idx] = $statusCode;
            $histogramCounts[$statusCode] = ($histogramCounts[$statusCode] ?? 0) + 1;

            if (($statusCode === 200 || $isValidCheckoutRedirect) && ! $isReplayed) {
                $successCount++;
            } elseif ($isReplayed || in_array($statusCode, [409, 422, 429], true)) {
                $rejectedCount++;
            } else {
                $serverErrorCount++;
                if ($firstErrorSample === 'N/A') {
                    $snippet = $rawBody !== '' ? mb_substr((string) preg_replace('/\s+/', ' ', strip_tags($rawBody)), 0, 220) : $curlError;
                    $firstErrorSample = "HTTP {$statusCode}: ".($snippet !== '' ? $snippet : 'Empty response');
                }
            }

            if ($firstErrorSample === 'N/A' && ! (($statusCode === 200 || $isValidCheckoutRedirect) && ! $isReplayed)) {
                $snippet = $rawBody !== '' ? mb_substr((string) preg_replace('/\s+/', ' ', strip_tags($rawBody)), 0, 220) : $curlError;
                $firstErrorSample = "HTTP {$statusCode}: ".($snippet !== '' ? $snippet : 'Replayed / Redirect');
            }

            curl_multi_remove_handle($mh, $ch);
        }

        unset($handles);
        curl_multi_close($mh);

        return [
            'success_count' => $successCount,
            'rejected_count' => $rejectedCount,
            'server_error_count' => $serverErrorCount,
            'duration_ms' => $durationMs,
            'status_codes' => $statusCodes,
            'histogram' => $this->formatStatusHistogram($histogramCounts),
            'first_error_sample' => $firstErrorSample,
        ];
    }

    /**
     * Format HTTP status code counts into a readable histogram string (e.g., "200:0, 302:5, 409:0, 419:0, 422:25, 429:0, 500:0, 0:0").
     *
     * @param  array<int, int>  $counts
     */
    public function formatStatusHistogram(array $counts): string
    {
        $parts = [];
        foreach ($counts as $code => $count) {
            if ($count > 0 || in_array($code, [200, 302, 419, 422, 429, 0], true)) {
                $parts[] = "{$code}:{$count}";
            }
        }

        return implode(', ', $parts);
    }

    private function drainCurlMulti(\CurlMultiHandle $mh): void
    {
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($active > 0 && $status === CURLM_OK);
    }
}
