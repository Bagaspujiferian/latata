<?php

namespace App\Services;

use App\Exceptions\FinancialInvariantException;
use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\PaymentWebhookLog;
use App\Support\StateTransitionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MidtransWebhookService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly OrderFulfillmentDispatcher $fulfillmentDispatcher,
        private readonly OrderStateAndSlaService $orderStateAndSlaService,
        private readonly VoucherAccountingService $voucherService,
        private readonly WalletTopUpService $walletTopUpService,
        private readonly NotificationService $notificationService,
    ) {}

    public function generateSignature(string $orderId, string $statusCode, string $grossAmount): string
    {
        $serverKey = (string) config('nexatrade.midtrans.server_key');

        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }

    public function verifySignature(string $orderId, string $statusCode, string $grossAmount, string $signatureKey): bool
    {
        if ($signatureKey === '') {
            return false;
        }

        $expected = $this->generateSignature($orderId, $statusCode, $grossAmount);

        return hash_equals($expected, $signatureKey);
    }

    /**
     * Process Midtrans payment webhook with SHA-512 verification, strict idempotency,
     * deterministic lock ordering, and Zombie Late Payment recovery.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handleNotification(array $payload): array
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');
        $transactionStatus = strtolower((string) ($payload['transaction_status'] ?? ''));
        $transactionId = (string) ($payload['transaction_id'] ?? ('MT-'.strtoupper(Str::random(10))));
        $fraudStatus = strtolower((string) ($payload['fraud_status'] ?? 'accept'));

        if (! $this->verifySignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
            $spoofLog = PaymentWebhookLog::create([
                'provider' => 'midtrans',
                'idempotency_key' => 'invalid_sig_'.substr($orderId !== '' ? $orderId : 'unknown', 0, 60).'_'.Str::uuid(),
                'signature_valid' => false,
                'http_status' => 403,
                'raw_payload' => $payload,
                'processing_notes' => 'REJECTED_INVALID_SHA512_SIGNATURE',
                'created_at' => now(),
            ]);

            return [
                'http_status' => 403,
                'signature_valid' => false,
                'idempotent_replay' => false,
                'webhook_log_id' => $spoofLog->id,
                'message' => 'Forbidden: Invalid Midtrans SHA-512 signature_key.',
            ];
        }

        return DB::transaction(function () use (
            $payload,
            $orderId,
            $statusCode,
            $grossAmount,
            $transactionStatus,
            $transactionId,
            $fraudStatus,
        ): array {
            $idempotencyKey = "midtrans_{$orderId}_{$transactionStatus}_{$statusCode}";
            $existingLog = PaymentWebhookLog::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existingLog) {
                return [
                    'http_status' => 200,
                    'signature_valid' => true,
                    'idempotent_replay' => true,
                    'idempotency_key' => $idempotencyKey,
                    'webhook_log_id' => $existingLog->id,
                    'message' => 'Idempotent replay detected: webhook already processed with zero duplicate side effects.',
                ];
            }

            // Explicit prefix separation: TOPUP- routes strictly to wallet_topups; INV- routes strictly to payment_invoices.
            $isPaidSignal = ($transactionStatus === 'settlement')
                || ($transactionStatus === 'capture' && $fraudStatus !== 'challenge');

            if (str_starts_with($orderId, 'TOPUP-')) {
                $topupRow = DB::table('wallet_topups')
                    ->where('topup_code', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $topupRow) {
                    $log = PaymentWebhookLog::create([
                        'provider' => 'midtrans',
                        'idempotency_key' => $idempotencyKey,
                        'signature_valid' => true,
                        'http_status' => 404,
                        'raw_payload' => $payload,
                        'processing_notes' => 'TOPUP_NOT_FOUND',
                        'created_at' => now(),
                    ]);

                    return [
                        'http_status' => 404,
                        'signature_valid' => true,
                        'idempotent_replay' => false,
                        'webhook_log_id' => $log->id,
                        'message' => "Tagihan Top-Up [{$orderId}] tidak ditemukan.",
                    ];
                }

                if ($isPaidSignal) {
                    $expectedTopupAmount = round((float) $topupRow->amount, 2);
                    $reportedGross = round((float) $grossAmount, 2);

                    if (abs($expectedTopupAmount - $reportedGross) > 0.01) {
                        throw new FinancialInvariantException(
                            "Top-Up gross amount mismatch on {$orderId}: expected {$expectedTopupAmount}, got {$reportedGross}."
                        );
                    }

                    $settledTopup = $this->walletTopUpService->settleTopUp($orderId, $transactionId);

                    $log = PaymentWebhookLog::create([
                        'provider' => 'midtrans',
                        'idempotency_key' => $idempotencyKey,
                        'signature_valid' => true,
                        'http_status' => 200,
                        'raw_payload' => $payload,
                        'processing_notes' => 'WALLET_TOPUP_SETTLED',
                        'created_at' => now(),
                    ]);

                    return [
                        'http_status' => 200,
                        'signature_valid' => true,
                        'idempotent_replay' => false,
                        'topup_code' => $settledTopup->topup_code,
                        'topup_status' => $settledTopup->status,
                        'webhook_log_id' => $log->id,
                        'message' => 'Midtrans wallet top-up settlement verified and credited (Anti-Gestun).',
                    ];
                }

                if (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true) && $topupRow->status === 'UNPAID') {
                    DB::table('wallet_topups')->where('id', $topupRow->id)->update([
                        'status' => 'EXPIRED',
                        'updated_at' => now(),
                    ]);
                }

                $log = PaymentWebhookLog::create([
                    'provider' => 'midtrans',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 200,
                    'raw_payload' => $payload,
                    'processing_notes' => "TOPUP_NON_FINAL_STATUS_{$transactionStatus}",
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 200,
                    'signature_valid' => true,
                    'idempotent_replay' => false,
                    'topup_code' => $orderId,
                    'webhook_log_id' => $log->id,
                    'message' => "Webhook top-up status [{$transactionStatus}] dicatat.",
                ];
            }

            if (! str_starts_with($orderId, 'INV-')) {
                $log = PaymentWebhookLog::create([
                    'provider' => 'midtrans',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 422,
                    'raw_payload' => $payload,
                    'processing_notes' => 'REJECTED_UNKNOWN_ORDER_ID_PREFIX',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 422,
                    'signature_valid' => true,
                    'idempotent_replay' => false,
                    'webhook_log_id' => $log->id,
                    'message' => 'Prefix order_id tidak dikenali (wajib INV- atau TOPUP-).',
                ];
            }

            $invoiceRow = DB::table('payment_invoices')
                ->where('invoice_code', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $invoiceRow) {
                $log = PaymentWebhookLog::create([
                    'provider' => 'midtrans',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 404,
                    'raw_payload' => $payload,
                    'processing_notes' => 'INVOICE_NOT_FOUND',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 404,
                    'signature_valid' => true,
                    'idempotent_replay' => false,
                    'webhook_log_id' => $log->id,
                    'message' => "Invoice [{$orderId}] tidak ditemukan.",
                ];
            }

            if ($isPaidSignal) {
                $expectedGross = round((float) $invoiceRow->grand_total, 2);
                $reportedGross = round((float) $grossAmount, 2);

                if (abs($expectedGross - $reportedGross) > 0.01) {
                    throw new FinancialInvariantException(
                        "Gross amount mismatch on {$orderId}: expected {$expectedGross}, got {$reportedGross}."
                    );
                }

                // Idempotent guard if already recovered as PAID_LATE_REFUNDED by an earlier webhook with different status_code
                if ($invoiceRow->status === 'PAID_LATE_REFUNDED') {
                    return [
                        'http_status' => 200,
                        'signature_valid' => true,
                        'idempotent_replay' => true,
                        'zombie_recovered' => true,
                        'invoice_code' => $invoiceRow->invoice_code,
                        'invoice_status' => 'PAID_LATE_REFUNDED',
                        'mutation_reference' => "LATEPAY-{$invoiceRow->invoice_code}",
                        'message' => 'Late payment refund already processed idempotently.',
                    ];
                }

                // LATE-PAYMENT WEBHOOK SAFE REFUND: invoice already EXPIRED -> NEVER re-lock stock/fulfill;
                // idempotently refund 100% of grand_total to buyer available_balance via LATE_PAYMENT_REFUND!
                if ($invoiceRow->status === 'EXPIRED') {
                    StateTransitionGuard::assertInvoiceTransition('EXPIRED', 'PAID_LATE_REFUNDED', "Late Payment {$orderId}");

                    DB::table('payment_invoices')->where('id', $invoiceRow->id)->update([
                        'status' => 'PAID_LATE_REFUNDED',
                        'midtrans_transaction_id' => $transactionId,
                        'paid_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $lateMutation = $this->ledgerService->creditLatePaymentRefund(
                        buyerId: (int) $invoiceRow->buyer_id,
                        invoiceId: (int) $invoiceRow->id,
                        amount: $expectedGross,
                        invoiceCode: $invoiceRow->invoice_code,
                    );

                    $log = PaymentWebhookLog::create([
                        'provider' => 'midtrans',
                        'idempotency_key' => $idempotencyKey,
                        'signature_valid' => true,
                        'http_status' => 200,
                        'raw_payload' => $payload,
                        'processing_notes' => "LATE_PAYMENT_REFUNDED: ref={$lateMutation->reference_code}",
                        'created_at' => now(),
                    ]);

                    $buyerId = (int) $invoiceRow->buyer_id;
                    $invCode = (string) $invoiceRow->invoice_code;
                    $invId = (int) $invoiceRow->id;

                    DB::afterCommit(function () use ($buyerId, $invCode, $invId, $expectedGross): void {
                        $this->notificationService->notify(
                            userId: $buyerId,
                            category: 'escrow',
                            title: "Pembayaran Terlambat {$invCode} Dikembalikan ke Dompet",
                            body: 'Tagihan telah melewati batas waktu saat pembayaran masuk. Dana Rp '.number_format($expectedGross, 0, ',', '.').' telah dikembalikan otomatis ke Saldo Aktif Anda.',
                            actionUrl: '/buyer/wallet',
                            refType: 'payment_invoice',
                            refId: $invId,
                        );
                    });

                    return [
                        'http_status' => 200,
                        'signature_valid' => true,
                        'idempotent_replay' => false,
                        'zombie_recovered' => true,
                        'invoice_code' => $invoiceRow->invoice_code,
                        'invoice_status' => 'PAID_LATE_REFUNDED',
                        'refund_amount' => $expectedGross,
                        'mutation_reference' => $lateMutation->reference_code,
                        'webhook_log_id' => $log->id,
                        'message' => 'Late payment safely refunded to buyer wallet (LATE_PAYMENT_REFUND) without stock re-lock.',
                    ];
                }

                // NORMAL SETTLEMENT
                StateTransitionGuard::assertInvoiceTransition($invoiceRow->status, 'PAID', "Midtrans Settlement {$orderId}");

                DB::table('payment_invoices')->where('id', $invoiceRow->id)->update([
                    'status' => 'PAID',
                    'midtrans_transaction_id' => $transactionId,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->voucherService->markVouchersAppliedForInvoice((int) $invoiceRow->id);

                // Deterministic ascending lock on all child orders.
                $childOrders = Order::where('payment_invoice_id', $invoiceRow->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $dispatchedSummary = [];
                foreach ($childOrders as $order) {
                    StateTransitionGuard::assertOrderTransition($order->status, 'PAID_ESCROW', "Midtrans {$order->order_code}");

                    DB::table('orders')->where('id', $order->id)->update([
                        'status' => 'PAID_ESCROW',
                        'updated_at' => now(),
                    ]);

                    $escrowMutation = $this->ledgerService->holdSellerEscrow(
                        sellerId: (int) $order->seller_id,
                        orderId: (int) $order->id,
                        amount: (float) $order->seller_escrow_amount,
                        orderCode: $order->order_code,
                    );

                    $dispatchedOrder = $this->fulfillmentDispatcher->dispatch($order->fresh());

                    $dispatchedSummary[] = [
                        'order_code' => $dispatchedOrder->order_code,
                        'seller_id' => (int) $dispatchedOrder->seller_id,
                        'delivery_method' => $dispatchedOrder->delivery_method,
                        'new_status' => $dispatchedOrder->status,
                        'escrow_hold_ref' => $escrowMutation->reference_code,
                        'seller_escrow_amount' => (float) $dispatchedOrder->seller_escrow_amount,
                    ];
                }

                $log = PaymentWebhookLog::create([
                    'provider' => 'midtrans',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 200,
                    'raw_payload' => $payload,
                    'processing_notes' => 'SETTLED_AND_DISPATCHED_'.count($dispatchedSummary).'_ORDERS',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 200,
                    'signature_valid' => true,
                    'idempotent_replay' => false,
                    'zombie_recovered' => false,
                    'invoice_code' => $invoiceRow->invoice_code,
                    'invoice_status' => 'PAID',
                    'dispatched_orders' => $dispatchedSummary,
                    'webhook_log_id' => $log->id,
                    'message' => 'Midtrans settlement verified and all child orders dispatched.',
                ];
            }

            if (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true) && $invoiceRow->status === 'UNPAID') {
                $invoiceModel = PaymentInvoice::findOrFail($invoiceRow->id);
                $expiryResult = $this->orderStateAndSlaService->expireInvoiceWithinTransaction(
                    $invoiceModel,
                    "Midtrans status: {$transactionStatus}",
                );

                $log = PaymentWebhookLog::create([
                    'provider' => 'midtrans',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 200,
                    'raw_payload' => $payload,
                    'processing_notes' => 'EXPIRED_VIA_MIDTRANS_WEBHOOK',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 200,
                    'signature_valid' => true,
                    'idempotent_replay' => false,
                    'invoice_code' => $invoiceRow->invoice_code,
                    'invoice_status' => 'EXPIRED',
                    'expiry_result' => $expiryResult,
                    'webhook_log_id' => $log->id,
                    'message' => 'Invoice expired and inventory/hybrid hold rolled back.',
                ];
            }

            $log = PaymentWebhookLog::create([
                'provider' => 'midtrans',
                'idempotency_key' => $idempotencyKey,
                'signature_valid' => true,
                'http_status' => 200,
                'raw_payload' => $payload,
                'processing_notes' => "ACKNOWLEDGED_NON_FINAL_STATUS_{$transactionStatus}",
                'created_at' => now(),
            ]);

            return [
                'http_status' => 200,
                'signature_valid' => true,
                'idempotent_replay' => false,
                'invoice_code' => $invoiceRow->invoice_code,
                'invoice_status' => $invoiceRow->status,
                'webhook_log_id' => $log->id,
                'message' => "Webhook status [{$transactionStatus}] dicatat.",
            ];
        });
    }
}
