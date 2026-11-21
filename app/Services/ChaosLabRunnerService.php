<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMutation;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChaosLabRunnerService
{
    public function __construct(
        private readonly CheckoutSplitOrderService $checkoutService,
        private readonly MidtransWebhookService $midtransWebhookService,
        private readonly VaultEncryptionService $vaultService,
        private readonly DigiflazzH2HService $h2hService,
        private readonly OrderStateAndSlaService $orderStateAndSlaService,
        private readonly EscrowLedgerService $ledgerService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runScenario(int $scenarioNumber): array
    {
        return match ($scenarioNumber) {
            1 => $this->test1ValidMidtransWebhook(),
            2 => $this->test2DuplicateWebhookReplayAttack(),
            3 => $this->test3SpoofedSignatureAttack(),
            4 => $this->test4ZombieLatePaymentRecovery(),
            5 => $this->test5HybridPaymentExpiryRollback(),
            6 => $this->test6BlindIndexDuplicateVaultGuard(),
            7 => $this->test7H2hAsyncFailureAutoRefund(),
            8 => $this->test8TimeTravelSlaAndAutoSettlement(),
            default => throw new DomainException("Skenario Chaos Lab #{$scenarioNumber} tidak dikenal (pilih 1-8)."),
        };
    }

    /**
     * Test 1 [Valid Midtrans Webhook]: Multi-seller split-order settlement + SHA-512 + Instant Vault decryption.
     *
     * @return array<string, mixed>
     */
    private function test1ValidMidtransWebhook(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();

        $vaultProduct = Product::with('gameCategory')
            ->where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $manualProduct = Product::with('gameCategory')
            ->where('seller_id', $seller2->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'manual_trade'))
            ->firstOrFail();

        // Ensure fresh encrypted voucher stock & active manual stock for repeatable testing.
        $freshCode = 'STEAM-CHAOS1-'.strtoupper(Str::random(8));
        $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [$freshCode]);
        if ($manualProduct->stock < 2) {
            DB::table('products')->where('id', $manualProduct->id)->increment('stock', 5);
        }
        DB::table('seller_profiles')->where('user_id', $seller2->id)->update(['is_store_open' => true]);

        $seller1PendingBefore = (float) Wallet::where('user_id', $seller1->id)->value('pending_balance');
        $seller2PendingBefore = (float) Wallet::where('user_id', $seller2->id)->value('pending_balance');

        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [
                [
                    'product_id' => $vaultProduct->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => [],
                ],
                [
                    'product_id' => $manualProduct->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => $this->sampleInputsForCategory($manualProduct->gameCategory->required_inputs_schema ?? []),
                    'verified_game_nickname' => 'CHAOS_TESTER_ID',
                ],
            ],
            clearCart: false,
        );

        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');
        $signature = $this->midtransWebhookService->generateSignature($invoice->invoice_code, '200', $grossStr);

        $webhookPayload = [
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-CHAOS1-'.strtoupper(Str::random(8)),
            'signature_key' => $signature,
        ];

        $webhookResult = $this->midtransWebhookService->handleNotification($webhookPayload);

        $invoice->refresh();
        $orders = Order::with('items')->where('payment_invoice_id', $invoice->id)->orderBy('id')->get();
        $vaultOrder = $orders->firstWhere('delivery_method', 'instant_vault');
        $manualOrder = $orders->firstWhere('delivery_method', 'manual_trade');

        $decryptedSecret = null;
        if ($vaultOrder && $vaultOrder->items->isNotEmpty()) {
            $decryptedSecret = $this->vaultService->decryptForAuthorizedBuyer($vaultOrder, $vaultOrder->items->first(), $buyer->id);
        }

        $seller1PendingAfter = (float) Wallet::where('user_id', $seller1->id)->value('pending_balance');
        $seller2PendingAfter = (float) Wallet::where('user_id', $seller2->id)->value('pending_balance');

        $passed = ($webhookResult['http_status'] === 200)
            && ($invoice->status === 'PAID')
            && ($orders->count() === 2)
            && ($vaultOrder?->status === 'DELIVERED')
            && ($manualOrder?->status === 'PROCESSING')
            && ($decryptedSecret !== null)
            && (abs(($seller1PendingAfter - $seller1PendingBefore) - (float) $vaultOrder->seller_escrow_amount) < 0.01)
            && (abs(($seller2PendingAfter - $seller2PendingBefore) - (float) $manualOrder->seller_escrow_amount) < 0.01);

        return [
            'scenario' => 1,
            'title' => 'Valid Midtrans SHA-512 Webhook + Multi-Seller Split Settlement',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'http_200_valid_sha512' => $webhookResult['http_status'] === 200 && ($webhookResult['signature_valid'] ?? false),
                'invoice_transition_unpaid_to_paid' => $invoice->status === 'PAID',
                'split_orders_created' => $orders->count(),
                'instant_vault_auto_delivered_and_decrypted' => $vaultOrder?->status === 'DELIVERED' && $decryptedSecret !== null,
                'manual_trade_sla_clock_started' => $manualOrder?->status === 'PROCESSING' && $manualOrder?->sla_deadline_at !== null,
                'seller_1_escrow_delta_exact' => round($seller1PendingAfter - $seller1PendingBefore, 2),
                'seller_2_escrow_delta_exact' => round($seller2PendingAfter - $seller2PendingBefore, 2),
            ],
            'audit_trace' => [
                'invoice_code' => $invoice->invoice_code,
                'sha512_signature_prefix' => substr($signature, 0, 32).'...',
                'vault_order_code' => $vaultOrder?->order_code,
                'decrypted_vault_preview' => $decryptedSecret,
                'manual_order_code' => $manualOrder?->order_code,
                'manual_sla_deadline' => $manualOrder?->sla_deadline_at?->toIso8601String(),
                'webhook_response' => $webhookResult,
            ],
        ];
    }

    /**
     * Test 2 [5x Duplicate Webhook Replay Attack]: Proves strict idempotency blocks 4/5 replays.
     *
     * @return array<string, mixed>
     */
    private function test2DuplicateWebhookReplayAttack(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [
            'REPLAY-SAFE-'.strtoupper(Str::random(10)),
        ]);

        $sellerPendingBefore = (float) Wallet::where('user_id', $seller1->id)->value('pending_balance');

        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'bca_va',
            explicitItems: [['product_id' => $vaultProduct->id, 'quantity' => 1]],
            clearCart: false,
        );

        $order = $invoice->orders()->firstOrFail();
        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');
        $signature = $this->midtransWebhookService->generateSignature($invoice->invoice_code, '200', $grossStr);

        $payload = [
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-REPLAY-'.strtoupper(Str::random(8)),
            'signature_key' => $signature,
        ];

        $attemptResults = [];
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->midtransWebhookService->handleNotification($payload);
            $attemptResults[] = [
                'attempt' => $i,
                'http_status' => $res['http_status'],
                'idempotent_replay' => $res['idempotent_replay'],
                'message' => $res['message'],
            ];
        }

        $sellerPendingAfter = (float) Wallet::where('user_id', $seller1->id)->value('pending_balance');
        $mutationCount = WalletMutation::where('order_id', $order->id)->where('mutation_type', 'ESCROW_HOLD')->count();
        $webhookLogCount = PaymentWebhookLog::where('idempotency_key', "midtrans_{$invoice->invoice_code}_settlement_200")->count();
        $blockedReplays = count(array_filter($attemptResults, fn (array $r): bool => $r['idempotent_replay'] === true));

        $passed = ($blockedReplays === 4)
            && ($mutationCount === 1)
            && ($webhookLogCount === 1)
            && (abs(($sellerPendingAfter - $sellerPendingBefore) - (float) $order->seller_escrow_amount) < 0.01);

        return [
            'scenario' => 2,
            'title' => '5x Duplicate Webhook Replay Attack (Strict Idempotency)',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'total_webhooks_fired' => 5,
                'first_execution_settled' => $attemptResults[0]['idempotent_replay'] === false,
                'duplicates_blocked_by_idempotency' => $blockedReplays,
                'escrow_hold_ledger_rows_created' => $mutationCount,
                'webhook_log_unique_rows' => $webhookLogCount,
                'seller_pending_credit_exact_1x' => round($sellerPendingAfter - $sellerPendingBefore, 2),
                'expected_1x_escrow' => (float) $order->seller_escrow_amount,
            ],
            'audit_trace' => [
                'invoice_code' => $invoice->invoice_code,
                'idempotency_key' => "midtrans_{$invoice->invoice_code}_settlement_200",
                'attempts' => $attemptResults,
            ],
        ];
    }

    /**
     * Test 3 [Spoofed Signature Attack]: Tampered gross_amount & forged SHA-512 -> HTTP 403.
     *
     * @return array<string, mixed>
     */
    private function test3SpoofedSignatureAttack(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [
            'SPOOF-GUARD-'.strtoupper(Str::random(10)),
        ]);

        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $vaultProduct->id, 'quantity' => 1]],
            clearCart: false,
        );

        $tamperedPayload = [
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => '1.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'MT-SPOOFED-9999',
            'signature_key' => hash('sha512', 'FORGED_ATTACKER_KEY_'.$invoice->invoice_code),
        ];

        $response = $this->midtransWebhookService->handleNotification($tamperedPayload);
        $invoice->refresh();

        $spoofLog = PaymentWebhookLog::find($response['webhook_log_id'] ?? 0);
        $passed = ($response['http_status'] === 403)
            && ($response['signature_valid'] === false)
            && ($invoice->status === 'UNPAID')
            && ($spoofLog !== null && $spoofLog->signature_valid === false && $spoofLog->http_status === 403);

        return [
            'scenario' => 3,
            'title' => 'Spoofed SHA-512 Signature & Tampered Amount Attack',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'http_status_403_forbidden' => $response['http_status'] === 403,
                'signature_valid_flag_false' => $response['signature_valid'] === false,
                'invoice_remained_unpaid' => $invoice->status === 'UNPAID',
                'attack_audited_in_webhook_logs' => $spoofLog !== null,
            ],
            'audit_trace' => [
                'invoice_code' => $invoice->invoice_code,
                'real_grand_total' => (float) $invoice->grand_total,
                'tampered_gross_amount' => '1.00',
                'webhook_log_id' => $spoofLog?->id,
                'response' => $response,
            ],
        ];
    }

    /**
     * Test 4 [Zombie Late Payment Recovery]: Sweep UNPAID -> EXPIRED, then fire late Midtrans settlement -> PAID_LATE_REFUNDED.
     *
     * @return array<string, mixed>
     */
    private function test4ZombieLatePaymentRecovery(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [
            'ZOMBIE-VAULT-'.strtoupper(Str::random(10)),
        ]);

        $stockBeforeCheckout = (int) Product::where('id', $vaultProduct->id)->value('stock');
        $buyerBalanceBefore = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');

        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $vaultProduct->id, 'quantity' => 1]],
            clearCart: false,
        );

        $stockWhileReserved = (int) Product::where('id', $vaultProduct->id)->value('stock');

        // Fast-forward expires_at and run the sweeper worker!
        DB::table('payment_invoices')->where('id', $invoice->id)->update([
            'expires_at' => now()->subMinutes(5),
        ]);
        $sweepResult = $this->orderStateAndSlaService->sweepExpiredInvoices();

        $invoice->refresh();
        $statusAfterSweep = $invoice->status;
        $stockAfterSweep = (int) Product::where('id', $vaultProduct->id)->value('stock');

        // Now fire a late Midtrans settlement webhook AFTER invoice expired!
        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');
        $signature = $this->midtransWebhookService->generateSignature($invoice->invoice_code, '200', $grossStr);

        $lateWebhookResult = $this->midtransWebhookService->handleNotification([
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-ZOMBIE-'.strtoupper(Str::random(8)),
            'signature_key' => $signature,
        ]);

        $invoice->refresh();
        $buyerBalanceAfter = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');
        $zombieMutation = WalletMutation::where('reference_code', "ZOMBIE-{$invoice->invoice_code}")->first();

        $passed = ($statusAfterSweep === 'EXPIRED')
            && ($stockAfterSweep === $stockBeforeCheckout)
            && ($invoice->status === 'PAID_LATE_REFUNDED')
            && ($zombieMutation !== null && $zombieMutation->mutation_type === 'ZOMBIE_PAYMENT_REFUND')
            && (abs(($buyerBalanceAfter - $buyerBalanceBefore) - (float) $invoice->grand_total) < 0.01);

        return [
            'scenario' => 4,
            'title' => 'Zombie Late Payment Recovery (EXPIRED -> PAID_LATE_REFUNDED)',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'vault_stock_reserved_then_released' => "{$stockBeforeCheckout} -> {$stockWhileReserved} -> {$stockAfterSweep}",
                'sweeper_marked_expired' => $statusAfterSweep === 'EXPIRED',
                'late_webhook_transitioned_paid_late_refunded' => $invoice->status === 'PAID_LATE_REFUNDED',
                'zombie_refund_credited_buyer_wallet' => round($buyerBalanceAfter - $buyerBalanceBefore, 2),
                'expected_grand_total_recovered' => (float) $invoice->grand_total,
            ],
            'audit_trace' => [
                'invoice_code' => $invoice->invoice_code,
                'sweep_summary' => $sweepResult,
                'late_webhook_response' => $lateWebhookResult,
                'zombie_ledger_mutation' => $zombieMutation?->only(['reference_code', 'mutation_type', 'amount', 'balance_before', 'balance_after']),
            ],
        ];
    }

    /**
     * Test 5 [Hybrid Payment Expiry Rollback]: Rp 25.000 Wallet Hold + QRIS -> Expire -> Automatic HYBRID_EXPIRY_REFUND.
     *
     * @return array<string, mixed>
     */
    private function test5HybridPaymentExpiryRollback(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        // Find a product priced > Rp 25.000 (e.g. Steam Wallet Rp 125.000 or Valorant VP)
        $product = Product::where('seller_id', $seller1->id)
            ->where('price', '>=', 50000)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $this->vaultService->bulkIngestVaultItems($product->id, $seller1->id, [
            'HYBRID-TEST-'.strtoupper(Str::random(10)),
        ]);

        // Ensure buyer has at least Rp 50.000 available.
        $buyerBalanceStart = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');
        if ($buyerBalanceStart < 50000) {
            $this->ledgerService->adminDemoTopup($buyer->id, 100000, 'DEMO-HYB-'.strtoupper(Str::random(8)));
            $buyerBalanceStart = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');
        }

        $hybridHoldAmount = 25000.00;
        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'hybrid_wallet_gateway',
            paymentChannel: 'qris',
            requestedWalletAmount: $hybridHoldAmount,
            explicitItems: [['product_id' => $product->id, 'quantity' => 1]],
            clearCart: false,
        );

        $buyerBalanceDuringHold = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');

        // Fast-forward expiry & run sweeper.
        DB::table('payment_invoices')->where('id', $invoice->id)->update([
            'expires_at' => now()->subMinutes(3),
        ]);
        $sweepResult = $this->orderStateAndSlaService->sweepExpiredInvoices();

        $invoice->refresh();
        $buyerBalanceAfterSweep = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');

        $holdMutation = WalletMutation::where('reference_code', "HYB-HOLD-{$invoice->invoice_code}")->first();
        $refundMutation = WalletMutation::where('reference_code', "HYB-EXP-{$invoice->invoice_code}")->first();

        $passed = (abs(($buyerBalanceStart - $buyerBalanceDuringHold) - $hybridHoldAmount) < 0.01)
            && ($invoice->status === 'EXPIRED')
            && (abs($buyerBalanceAfterSweep - $buyerBalanceStart) < 0.01)
            && ($holdMutation?->mutation_type === 'HYBRID_WALLET_HOLD')
            && ($refundMutation?->mutation_type === 'HYBRID_EXPIRY_REFUND');

        return [
            'scenario' => 5,
            'title' => 'Hybrid Payment Expiry Rollback (HYBRID_WALLET_HOLD -> HYBRID_EXPIRY_REFUND)',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'wallet_balance_before' => $buyerBalanceStart,
                'wallet_balance_during_hold' => $buyerBalanceDuringHold,
                'wallet_balance_after_expiry_sweep' => $buyerBalanceAfterSweep,
                'net_buyer_loss_rupiah' => round($buyerBalanceStart - $buyerBalanceAfterSweep, 2),
                'invoice_status' => $invoice->status,
            ],
            'audit_trace' => [
                'invoice_code' => $invoice->invoice_code,
                'subtotal_amount' => (float) $invoice->subtotal_amount,
                'wallet_used_amount' => (float) $invoice->wallet_used_amount,
                'gateway_grand_total' => (float) $invoice->grand_total,
                'hold_mutation' => $holdMutation?->only(['reference_code', 'mutation_type', 'amount', 'balance_before', 'balance_after']),
                'refund_mutation' => $refundMutation?->only(['reference_code', 'mutation_type', 'amount', 'balance_before', 'balance_after']),
                'sweep_summary' => $sweepResult,
            ],
        ];
    }

    /**
     * Test 6 [AES-256 Blind-Index Duplicate Vault Guard]: Proves HMAC-SHA256 blocks duplicates despite random IV ciphertexts.
     *
     * @return array<string, mixed>
     */
    private function test6BlindIndexDuplicateVaultGuard(): array
    {
        $startedAt = microtime(true);
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $duplicatePlaintext = 'STEAM-DEDUP-'.strtoupper(Str::random(6));
        $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [$duplicatePlaintext]);

        // Demonstrate AES-256 random IV produces different ciphertexts for the exact same string!
        $ciphertextA = Crypt::encryptString($duplicatePlaintext);
        $ciphertextB = Crypt::encryptString($duplicatePlaintext);
        $blindHashA = $this->vaultService->computeBlindHash($duplicatePlaintext);
        $blindHashB = $this->vaultService->computeBlindHash('  '.strtolower($duplicatePlaintext).'  ');

        $newCode1 = 'STEAM-NEW1-'.strtoupper(Str::random(8));
        $newCode2 = 'STEAM-NEW2-'.strtoupper(Str::random(8));

        // Attempt to ingest 3 codes where 1 is a case/whitespace-varied duplicate of existing vault!
        $ingestReport = $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [
            '  '.strtolower($duplicatePlaintext).'  ',
            $newCode1,
            $newCode2,
        ]);

        $actualAvailableCount = ProductVault::where('product_id', $vaultProduct->id)->where('status', 'available')->count();
        $syncedProductStock = (int) Product::where('id', $vaultProduct->id)->value('stock');

        $passed = ($ciphertextA !== $ciphertextB)
            && ($blindHashA === $blindHashB)
            && ($ingestReport['inserted_count'] === 2)
            && ($ingestReport['duplicate_count'] === 1)
            && ($syncedProductStock === $actualAvailableCount);

        return [
            'scenario' => 6,
            'title' => 'AES-256 Blind-Index Duplicate Vault Guard & Stock Sync',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'aes256_ciphertexts_differ_due_to_random_iv' => $ciphertextA !== $ciphertextB,
                'hmac_sha256_blind_hashes_identical' => $blindHashA === $blindHashB,
                'submitted_lines' => 3,
                'inserted_unique_codes' => $ingestReport['inserted_count'],
                'blocked_duplicates' => $ingestReport['duplicate_count'],
                'vault_driven_stock_sync_exact' => "{$syncedProductStock} === {$actualAvailableCount}",
            ],
            'audit_trace' => [
                'product_sku' => $vaultProduct->sku_code,
                'ciphertext_sample_1_prefix' => substr($ciphertextA, 0, 36).'...',
                'ciphertext_sample_2_prefix' => substr($ciphertextB, 0, 36).'...',
                'blind_index_hash_sha256' => $blindHashA,
                'ingest_report' => $ingestReport,
            ],
        ];
    }

    /**
     * Test 7 [H2H Async Failure Auto-Refund]: Digiflazz webhook 'gagal' -> REFUNDED + Seller Debit + Buyer Credit.
     *
     * @return array<string, mixed>
     */
    private function test7H2hAsyncFailureAutoRefund(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();

        $h2hProduct = Product::with('gameCategory')
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'api_h2h'))
            ->firstOrFail();

        if ($h2hProduct->stock < 3) {
            DB::table('products')->where('id', $h2hProduct->id)->increment('stock', 10);
        }

        // Top up buyer if needed for instant wallet settlement.
        $buyerAvailableCheck = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');
        if ($buyerAvailableCheck < (float) $h2hProduct->price + 5000) {
            $this->ledgerService->adminDemoTopup($buyer->id, 100000, 'DEMO-H2H-'.strtoupper(Str::random(8)));
        }

        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [
                [
                    'product_id' => $h2hProduct->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => $this->sampleInputsForCategory($h2hProduct->gameCategory->required_inputs_schema ?? []),
                    'verified_game_nickname' => 'EVOS•KaguraID',
                ],
            ],
            clearCart: false,
        );

        $order = $invoice->orders()->firstOrFail();
        $buyerBeforeWebhook = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');
        $sellerPendingBeforeWebhook = (float) Wallet::where('user_id', $order->seller_id)->value('pending_balance');
        $stockBeforeWebhook = (int) Product::where('id', $h2hProduct->id)->value('stock');

        $h2hResponse = $this->h2hService->handleH2HWebhook(
            refId: (string) $order->h2h_reference_id,
            status: 'gagal',
            snOrMessage: 'RC 54: Target User ID tidak ditemukan / Server Moonton Timeout',
            rawPayload: [
                'data' => [
                    'ref_id' => $order->h2h_reference_id,
                    'status' => 'Gagal',
                    'rc' => '54',
                    'message' => 'Target User ID tidak ditemukan / Server Moonton Timeout',
                ],
            ],
        );

        $order->refresh();
        $buyerAfterWebhook = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');
        $sellerPendingAfterWebhook = (float) Wallet::where('user_id', $order->seller_id)->value('pending_balance');
        $stockAfterWebhook = (int) Product::where('id', $h2hProduct->id)->value('stock');

        $passed = ($h2hResponse['http_status'] === 200)
            && ($order->status === 'REFUNDED')
            && ($stockAfterWebhook === $stockBeforeWebhook + 1)
            && (abs(($buyerAfterWebhook - $buyerBeforeWebhook) - (float) $order->gross_amount) < 0.01)
            && (abs(($sellerPendingBeforeWebhook - $sellerPendingAfterWebhook) - (float) $order->seller_escrow_amount) < 0.01);

        return [
            'scenario' => 7,
            'title' => 'Digiflazz H2H Async Failure Auto-Reversal & Instant Refund',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'order_transition_processing_to_refunded' => $order->status === 'REFUNDED',
                'buyer_wallet_refunded_100_percent' => round($buyerAfterWebhook - $buyerBeforeWebhook, 2),
                'seller_pending_escrow_debited' => round($sellerPendingBeforeWebhook - $sellerPendingAfterWebhook, 2),
                'product_stock_restored' => "{$stockBeforeWebhook} -> {$stockAfterWebhook}",
            ],
            'audit_trace' => [
                'order_code' => $order->order_code,
                'h2h_reference_id' => $order->h2h_reference_id,
                'h2h_webhook_result' => $h2hResponse,
                'cancelled_reason' => $order->cancelled_reason,
            ],
        ];
    }

    /**
     * Test 8 [Time-Travel SLA Breach & 24h Auto-Settlement]: Proves both SLA breach refund and 24h escrow release.
     *
     * @return array<string, mixed>
     */
    private function test8TimeTravelSlaAndAutoSettlement(): array
    {
        $startedAt = microtime(true);
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();

        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $manualProduct = Product::with('gameCategory')
            ->where('seller_id', $seller2->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'manual_trade'))
            ->firstOrFail();

        $this->vaultService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [
            'AUTOSETTLE-'.strtoupper(Str::random(10)),
        ]);
        if ($manualProduct->stock < 3) {
            DB::table('products')->where('id', $manualProduct->id)->increment('stock', 5);
        }
        DB::table('seller_profiles')->where('user_id', $seller2->id)->update(['is_store_open' => true]);

        $needed = (float) $vaultProduct->price + (float) $manualProduct->price + 10000;
        if ((float) Wallet::where('user_id', $buyer->id)->value('available_balance') < $needed) {
            $this->ledgerService->adminDemoTopup($buyer->id, $needed, 'DEMO-TT-'.strtoupper(Str::random(8)));
        }

        // Create 1 split-order invoice paid via wallet_full -> yields 1 DELIVERED (vault) + 1 PROCESSING (manual)!
        $invoice = $this->checkoutService->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [
                ['product_id' => $vaultProduct->id, 'quantity' => 1],
                [
                    'product_id' => $manualProduct->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => $this->sampleInputsForCategory($manualProduct->gameCategory->required_inputs_schema ?? []),
                    'verified_game_nickname' => 'TIMETRAVEL_ID',
                ],
            ],
            clearCart: false,
        );

        $orders = $invoice->orders()->get();
        $deliveredOrder = $orders->firstWhere('delivery_method', 'instant_vault');
        $processingOrder = $orders->firstWhere('delivery_method', 'manual_trade');

        // Time-travel BOTH orders!
        DB::table('orders')->where('id', $processingOrder->id)->update([
            'sla_deadline_at' => now()->subMinutes(15),
        ]);
        DB::table('orders')->where('id', $deliveredOrder->id)->update([
            'auto_complete_at' => now()->subHours(25),
        ]);

        $seller1AvailBefore = (float) Wallet::where('user_id', $seller1->id)->value('available_balance');
        $seller1PendingBefore = (float) Wallet::where('user_id', $seller1->id)->value('pending_balance');
        $buyerAvailBeforeSla = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');

        // Execute SLA breach refund on the time-traveled PROCESSING order + Auto-Settlement worker on DELIVERED order!
        $refundedOrder = $this->orderStateAndSlaService->buyerClaimSlaBreachRefund($processingOrder->id, $buyer->id);
        $settleResult = $this->orderStateAndSlaService->settleCompletedOrders();

        $deliveredOrder->refresh();
        $seller1AvailAfter = (float) Wallet::where('user_id', $seller1->id)->value('available_balance');
        $seller1PendingAfter = (float) Wallet::where('user_id', $seller1->id)->value('pending_balance');
        $buyerAvailAfterSla = (float) Wallet::where('user_id', $buyer->id)->value('available_balance');

        $relDebit = WalletMutation::where('reference_code', "REL-DEBIT-{$deliveredOrder->order_code}")->first();
        $relCredit = WalletMutation::where('reference_code', "REL-CREDIT-{$deliveredOrder->order_code}")->first();

        $passed = ($refundedOrder->status === 'REFUNDED')
            && ($deliveredOrder->status === 'COMPLETED')
            && ($relDebit !== null && $relCredit !== null)
            && (abs(($buyerAvailAfterSla - $buyerAvailBeforeSla) - (float) $processingOrder->gross_amount) < 0.01)
            && (abs(($seller1AvailAfter - $seller1AvailBefore) - (float) $deliveredOrder->seller_escrow_amount) < 0.01)
            && (abs(($seller1PendingBefore - $seller1PendingAfter) - (float) $deliveredOrder->seller_escrow_amount) < 0.01);

        return [
            'scenario' => 8,
            'title' => 'Time-Travel SLA Breach (-15m) & 24h Auto-Settlement (-25h)',
            'passed' => $passed,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'assertions' => [
                'sla_order_refunded_instantly' => $refundedOrder->status === 'REFUNDED',
                'buyer_sla_refund_received' => round($buyerAvailAfterSla - $buyerAvailBeforeSla, 2),
                'delivered_order_auto_completed' => $deliveredOrder->status === 'COMPLETED',
                'seller_pending_to_available_exact' => round($seller1AvailAfter - $seller1AvailBefore, 2),
                'double_entry_release_verified' => $relDebit !== null && $relCredit !== null,
            ],
            'audit_trace' => [
                'sla_breach_order_code' => $refundedOrder->order_code,
                'auto_settled_order_code' => $deliveredOrder->order_code,
                'release_debit_mutation' => $relDebit?->only(['reference_code', 'balance_bucket', 'direction', 'amount', 'balance_before', 'balance_after']),
                'release_credit_mutation' => $relCredit?->only(['reference_code', 'balance_bucket', 'direction', 'amount', 'balance_before', 'balance_after']),
                'worker_summary' => $settleResult,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @return array<string, string>
     */
    private function sampleInputsForCategory(array $schema): array
    {
        $values = [];
        foreach ($schema as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($key === '') {
                continue;
            }

            $values[$key] = match ($key) {
                'user_id' => '123456789',
                'zone_id' => '2041',
                'grow_id' => 'NexaGrowPro',
                'world_name' => 'NEXADROP88',
                'riot_id' => 'JettMaster#JKT1',
                'uid' => '812345678',
                'server' => 'Asia',
                default => $type === 'number' ? '888999' : 'NEXA_TEST_INPUT',
            };
        }

        return $values;
    }
}
