<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentWebhookLog;
use App\Models\User;
use App\Support\StateTransitionGuard;
use DomainException;
use Illuminate\Support\Facades\DB;

class DigiflazzH2HService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly SellerSlaMetricsService $slaMetricsService,
        private readonly ChatModerationService $chatModerationService,
    ) {}

    /**
     * Build and record a deterministic H2H top-up dispatch to Digiflazz.
     *
     * @return array<string, mixed>
     */
    public function dispatchTopUp(Order $order): array
    {
        $order->loadMissing(['items.product']);
        $firstItem = $order->items->first();
        $buyerSkuCode = $firstItem?->product?->h2h_provider_sku ?? $firstItem?->sku_snapshot ?? 'MLBB-86DM';

        $refId = $order->order_code;
        $username = (string) config('nexatrade.digiflazz.username');
        $apiKey = (string) config('nexatrade.digiflazz.api_key');
        $signature = md5($username.$apiKey.$refId);

        $inputs = $order->buyer_game_inputs ?? [];
        $customerNo = $this->formatCustomerNo($inputs);

        $requestPayload = [
            'username' => $username,
            'buyer_sku_code' => $buyerSkuCode,
            'customer_no' => $customerNo,
            'ref_id' => $refId,
            'sign' => $signature,
            'testing' => ! (bool) config('nexatrade.midtrans.is_production', false),
        ];

        $responseLog = [
            'provider' => 'Digiflazz H2H',
            'endpoint' => (string) config('nexatrade.digiflazz.endpoint'),
            'ref_id' => $refId,
            'signature_md5' => $signature,
            'request' => $requestPayload,
            'initial_response' => [
                'rc' => '03',
                'status' => 'Pending',
                'message' => 'Transaksi sedang diproses oleh server game provider (async webhook standby).',
                'customer_no' => $customerNo,
                'buyer_sku_code' => $buyerSkuCode,
            ],
            'dispatched_at' => now()->toIso8601String(),
        ];

        DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'h2h_reference_id' => $refId,
                'h2h_response_log' => json_encode($responseLog),
                'updated_at' => now(),
            ]);

        return $responseLog;
    }

    /**
     * Handle asynchronous H2H provider callback ('sukses' or 'gagal').
     *
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    public function handleH2HWebhook(string $refId, string $status, string $snOrMessage, array $rawPayload = []): array
    {
        $normalizedStatus = strtolower(trim($status));
        if (! in_array($normalizedStatus, ['sukses', 'gagal', 'pending'], true)) {
            throw new DomainException("Status webhook Digiflazz H2H tidak dikenali: [{$status}].");
        }

        return DB::transaction(function () use ($refId, $normalizedStatus, $snOrMessage, $rawPayload): array {
            $idempotencyKey = "digiflazz_{$refId}_{$normalizedStatus}";
            $existingLog = PaymentWebhookLog::where('idempotency_key', $idempotencyKey)->first();

            if ($existingLog) {
                return [
                    'http_status' => 200,
                    'idempotent_replay' => true,
                    'order_code' => $refId,
                    'status' => $normalizedStatus,
                    'message' => 'Duplicate H2H webhook ignored safely via idempotency guard.',
                ];
            }

            $orderRow = DB::table('orders')
                ->where('h2h_reference_id', $refId)
                ->lockForUpdate()
                ->first();

            if (! $orderRow) {
                PaymentWebhookLog::create([
                    'provider' => 'digiflazz_h2h',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 404,
                    'raw_payload' => $rawPayload ?: ['ref_id' => $refId, 'status' => $normalizedStatus, 'sn' => $snOrMessage],
                    'processing_notes' => 'ORDER_NOT_FOUND_FOR_H2H_REF',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 404,
                    'idempotent_replay' => false,
                    'order_code' => $refId,
                    'message' => "Order dengan h2h_reference_id [{$refId}] tidak ditemukan.",
                ];
            }

            $existingH2hLog = json_decode((string) $orderRow->h2h_response_log, true) ?? [];
            $existingH2hLog['webhook_callback'] = [
                'status' => $normalizedStatus,
                'sn_or_message' => $snOrMessage,
                'received_at' => now()->toIso8601String(),
                'raw' => $rawPayload,
            ];

            $orderModel = Order::with('items')->findOrFail($orderRow->id);
            $sellerUser = User::findOrFail($orderModel->seller_id);

            if ($normalizedStatus === 'pending') {
                DB::table('orders')->where('id', $orderModel->id)->update([
                    'h2h_response_log' => json_encode($existingH2hLog),
                    'updated_at' => now(),
                ]);

                PaymentWebhookLog::create([
                    'provider' => 'digiflazz_h2h',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 200,
                    'raw_payload' => $rawPayload ?: ['ref_id' => $refId, 'status' => $normalizedStatus, 'message' => $snOrMessage],
                    'processing_notes' => 'H2H_STILL_PENDING',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 200,
                    'idempotent_replay' => false,
                    'order_code' => $orderModel->order_code,
                    'order_status' => $orderModel->status,
                    'message' => 'H2H masih berstatus pending.',
                ];
            }

            if ($normalizedStatus === 'sukses') {
                StateTransitionGuard::assertOrderTransition($orderRow->status, 'DELIVERED', "H2H sukses {$refId}");

                $deliveredAt = now();
                $autoCompleteAt = $deliveredAt->copy()->addHours((int) config('nexatrade.auto_complete_hours', 24));
                $proof = [
                    'type' => 'api_h2h_sn',
                    'provider' => 'Digiflazz H2H',
                    'serial_number' => $snOrMessage,
                    'ref_id' => $refId,
                    'delivered_at' => $deliveredAt->toIso8601String(),
                ];

                DB::table('orders')->where('id', $orderModel->id)->update([
                    'status' => 'DELIVERED',
                    'seller_delivery_proof' => json_encode($proof),
                    'h2h_response_log' => json_encode($existingH2hLog),
                    'delivered_at' => $deliveredAt,
                    'auto_complete_at' => $autoCompleteAt,
                    'updated_at' => now(),
                ]);

                $this->slaMetricsService->recalculateSellerSla((int) $orderModel->seller_id);

                $this->chatModerationService->postOrderMessage(
                    order: $orderModel,
                    sender: $sellerUser,
                    rawMessage: "[SISTEM H2H SUKSES] Top-up otomatis selesai diproses oleh Digiflazz H2H. Serial Number (SN): {$snOrMessage}. Dana akan cair otomatis dalam 24 jam jika tidak ada komplain.",
                    senderRoleOverride: 'system',
                );

                PaymentWebhookLog::create([
                    'provider' => 'digiflazz_h2h',
                    'idempotency_key' => $idempotencyKey,
                    'signature_valid' => true,
                    'http_status' => 200,
                    'raw_payload' => $rawPayload ?: ['ref_id' => $refId, 'status' => 'sukses', 'sn' => $snOrMessage],
                    'processing_notes' => 'H2H_DELIVERED_WITH_SN',
                    'created_at' => now(),
                ]);

                return [
                    'http_status' => 200,
                    'idempotent_replay' => false,
                    'order_code' => $orderModel->order_code,
                    'order_status' => 'DELIVERED',
                    'serial_number' => $snOrMessage,
                    'message' => 'H2H sukses: pesanan berstatus DELIVERED.',
                ];
            }

            // Status === 'gagal' -> Automated H2H Reversal & Instant Buyer Wallet Refund!
            StateTransitionGuard::assertOrderTransition($orderRow->status, 'REFUNDED', "H2H gagal {$refId}");

            // Restore non-vault product stock with ascending product lock to prevent deadlocks.
            $productIds = $orderModel->items->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->sort()->values()->all();
            if (! empty($productIds)) {
                DB::table('products')->whereIn('id', $productIds)->orderBy('id', 'asc')->lockForUpdate()->get();
                foreach ($orderModel->items as $item) {
                    DB::table('products')
                        ->where('id', $item->product_id)
                        ->increment('stock', (int) $item->quantity);
                }
            }

            DB::table('orders')->where('id', $orderModel->id)->update([
                'status' => 'REFUNDED',
                'cancelled_reason' => mb_substr("H2H Auto-Reversal (Provider Gagal): {$snOrMessage}", 0, 255),
                'h2h_response_log' => json_encode($existingH2hLog),
                'updated_at' => now(),
            ]);

            $mutations = $this->ledgerService->refundEscrowToBuyer(
                sellerId: (int) $orderModel->seller_id,
                buyerId: (int) $orderModel->buyer_id,
                orderId: (int) $orderModel->id,
                sellerEscrowAmount: (float) $orderModel->seller_escrow_amount,
                buyerRefundAmount: (float) $orderModel->gross_amount,
                orderCode: $orderModel->order_code,
                reason: "H2H Provider Gagal ({$snOrMessage})",
            );

            $this->chatModerationService->postOrderMessage(
                order: $orderModel,
                sender: $sellerUser,
                rawMessage: "[SISTEM H2H AUTO-REFUND] Server provider melaporkan kegagalan ({$snOrMessage}). Dana Rp ".number_format((float) $orderModel->gross_amount, 0, ',', '.').' dikembalikan 100% ke Saldo Aktif Pembeli secara instan tanpa menunggu admin.',
                senderRoleOverride: 'system',
            );

            PaymentWebhookLog::create([
                'provider' => 'digiflazz_h2h',
                'idempotency_key' => $idempotencyKey,
                'signature_valid' => true,
                'http_status' => 200,
                'raw_payload' => $rawPayload ?: ['ref_id' => $refId, 'status' => 'gagal', 'message' => $snOrMessage],
                'processing_notes' => 'H2H_FAILED_AUTO_REFUNDED_TO_BUYER',
                'created_at' => now(),
            ]);

            return [
                'http_status' => 200,
                'idempotent_replay' => false,
                'order_code' => $orderModel->order_code,
                'order_status' => 'REFUNDED',
                'refund_mutations' => [
                    'seller_debit_ref' => $mutations['seller_debit']->reference_code,
                    'buyer_credit_ref' => $mutations['buyer_credit']->reference_code,
                    'buyer_refunded_amount' => (float) $orderModel->gross_amount,
                ],
                'message' => 'H2H gagal: pesanan direfund otomatis ke saldo dompet pembeli.',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function formatCustomerNo(array $inputs): string
    {
        if (isset($inputs['user_id'], $inputs['zone_id'])) {
            return trim((string) $inputs['user_id']).trim((string) $inputs['zone_id']);
        }

        if (isset($inputs['riot_id'])) {
            return trim((string) $inputs['riot_id']);
        }

        if (isset($inputs['uid'])) {
            return trim((string) $inputs['uid']);
        }

        return implode('-', array_map(fn ($v) => trim((string) $v), $inputs)) ?: 'NEXA-DIRECT';
    }
}
