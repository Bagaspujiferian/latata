<?php

namespace App\Services;

use App\Exceptions\InsufficientVaultStockException;
use App\Jobs\DispatchH2HOrderJob;
use App\Models\Order;
use App\Models\User;
use App\Support\StateTransitionGuard;
use Illuminate\Support\Facades\DB;

class OrderFulfillmentDispatcher
{
    public function __construct(
        private readonly SellerSlaMetricsService $slaMetricsService,
        private readonly ChatModerationService $chatModerationService,
        private readonly NotificationService $notificationService,
        private readonly AccountWarrantyService $warrantyService,
    ) {}

    /**
     * Must be invoked inside an active DB transaction after order row is locked in PAID_ESCROW.
     */
    public function dispatch(Order $order): Order
    {
        $orderRow = DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
        if (! $orderRow) {
            return $order;
        }

        $sellerUser = User::findOrFail((int) $orderRow->seller_id);

        if ($orderRow->delivery_method === 'instant_vault') {
            StateTransitionGuard::assertOrderTransition($orderRow->status, 'DELIVERED', "Instant Vault {$orderRow->order_code}");

            $orderItems = DB::table('order_items')
                ->where('order_id', $orderRow->id)
                ->orderBy('id', 'asc')
                ->get();

            $vaultIds = $orderItems->pluck('product_vault_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all();
            if (count($vaultIds) !== $orderItems->count()) {
                throw new InsufficientVaultStockException("Alokasi brankas untuk pesanan {$orderRow->order_code} tidak lengkap.");
            }

            $lockedVaults = DB::table('product_vaults')
                ->whereIn('id', $vaultIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $now = now();
            $soldCountByProduct = [];

            foreach ($orderItems as $item) {
                $vault = $lockedVaults->get((int) $item->product_vault_id);
                if (! $vault || $vault->status !== 'reserved' || (int) $vault->reserved_order_id !== (int) $orderRow->id) {
                    throw new InsufficientVaultStockException("Brankas #{$item->product_vault_id} tidak valid untuk pesanan {$orderRow->order_code}.");
                }

                DB::table('product_vaults')
                    ->where('id', $vault->id)
                    ->update([
                        'status' => 'sold',
                        'sold_at' => $now,
                        'updated_at' => $now,
                    ]);

                DB::table('order_items')
                    ->where('id', $item->id)
                    ->update([
                        'encrypted_delivery_payload' => $vault->encrypted_payload,
                        'updated_at' => $now,
                    ]);

                $productId = (int) $item->product_id;
                $soldCountByProduct[$productId] = ($soldCountByProduct[$productId] ?? 0) + (int) $item->quantity;
            }

            // Lock products ascending before updating sold_count.
            $sortedProductIds = array_keys($soldCountByProduct);
            sort($sortedProductIds, SORT_NUMERIC);
            if (! empty($sortedProductIds)) {
                DB::table('products')->whereIn('id', $sortedProductIds)->orderBy('id', 'asc')->lockForUpdate()->get();
                foreach ($sortedProductIds as $pid) {
                    DB::table('products')->where('id', $pid)->increment('sold_count', $soldCountByProduct[$pid]);
                }
            }

            $holdHours = (int) (DB::table('order_items')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->join('game_categories', 'game_categories.id', '=', 'products.game_category_id')
                ->where('order_items.order_id', $orderRow->id)
                ->max('game_categories.escrow_hold_hours') ?: config('nexatrade.auto_complete_hours', 24));

            $autoCompleteAt = $now->copy()->addHours(max(1, $holdHours));
            DB::table('orders')->where('id', $orderRow->id)->update([
                'status' => 'DELIVERED',
                'delivered_at' => $now,
                'auto_complete_at' => $autoCompleteAt,
                'seller_delivery_proof' => json_encode([
                    'type' => 'instant_vault_aes256',
                    'vault_items_delivered' => count($vaultIds),
                    'delivered_at' => $now->toIso8601String(),
                    'notes' => 'Kode/kredensial terenkripsi AES-256 dikirim otomatis dari Brankas NexaTrade.',
                ]),
                'updated_at' => $now,
            ]);

            $this->slaMetricsService->recalculateSellerSla((int) $orderRow->seller_id);

            $freshOrder = Order::with(['items.product.gameCategory', 'paymentInvoice'])->findOrFail((int) $orderRow->id);
            $this->warrantyService->issueWarrantyIfEligible($freshOrder);
            $this->chatModerationService->postOrderMessage(
                order: $freshOrder,
                sender: $sellerUser,
                rawMessage: '[BRANKAS INSTAN AES-256] Pembayaran terverifikasi! Kode voucher / kredensial akun telah dikirim otomatis ke Brankas Digital Anda. Silakan klik "Buka Segel AES-256" untuk melihat isi pesanan.',
                senderRoleOverride: 'system',
            );

            $this->notificationService->notify(
                userId: (int) $orderRow->seller_id,
                category: 'escrow',
                title: "Pesanan Instan Terkirim: {$freshOrder->order_code}",
                body: 'Brankas AES-256 otomatis mengirim pesanan. Escrow Rp '.number_format((float) $freshOrder->seller_escrow_amount, 0, ',', '.').' masuk Pending Escrow.',
                actionUrl: '/seller/orders',
                refType: 'order',
                refId: (int) $freshOrder->id,
            );

            $this->notificationService->notify(
                userId: (int) $orderRow->buyer_id,
                category: 'order',
                title: "Pesanan Instan Siap Dibuka: {$freshOrder->order_code}",
                body: 'Pembayaran terverifikasi! Buka segel AES-256 di detail pesanan untuk mengambil voucher/akun Anda.',
                actionUrl: "/buyer/orders/{$freshOrder->order_code}",
                refType: 'order',
                refId: (int) $freshOrder->id,
            );

            return $freshOrder;
        }

        if ($orderRow->delivery_method === 'api_h2h') {
            StateTransitionGuard::assertOrderTransition($orderRow->status, 'PROCESSING', "API H2H {$orderRow->order_code}");

            $slaMinutes = max(1, (int) $orderRow->sla_target_minutes_snapshot);
            $slaDeadline = now()->addMinutes($slaMinutes);

            DB::table('orders')->where('id', $orderRow->id)->update([
                'status' => 'PROCESSING',
                'sla_deadline_at' => $slaDeadline,
                'updated_at' => now(),
            ]);

            $freshOrder = Order::with(['items.product', 'paymentInvoice'])->findOrFail((int) $orderRow->id);
            $freshOrderId = (int) $freshOrder->id;
            DB::afterCommit(static function () use ($freshOrderId): void {
                DispatchH2HOrderJob::dispatch($freshOrderId)->afterCommit();
            });

            $this->chatModerationService->postOrderMessage(
                order: $freshOrder,
                sender: $sellerUser,
                rawMessage: "[DISPATCH H2H OTOMATIS] Permintaan top-up (Ref: {$freshOrder->order_code}) telah dikirim ke Digiflazz H2H Gateway. Target SLA {$slaMinutes} menit. Jika server gagal, dana dikembalikan otomatis ke Saldo Dompet Anda.",
                senderRoleOverride: 'system',
            );

            $this->notificationService->notify(
                userId: (int) $orderRow->seller_id,
                category: 'order',
                title: "Pesanan Top-Up H2H Masuk: {$freshOrder->order_code}",
                body: 'Top-up H2H didispatch ke Digiflazz. Escrow Rp '.number_format((float) $freshOrder->seller_escrow_amount, 0, ',', '.').' diamankan.',
                actionUrl: '/seller/orders',
                refType: 'order',
                refId: (int) $freshOrder->id,
            );

            return $freshOrder->refresh()->load(['items', 'paymentInvoice']);
        }

        // manual_trade
        StateTransitionGuard::assertOrderTransition($orderRow->status, 'PROCESSING', "Manual Trade {$orderRow->order_code}");

        $slaMinutes = max(1, (int) $orderRow->sla_target_minutes_snapshot);
        $slaDeadline = now()->addMinutes($slaMinutes);

        DB::table('orders')->where('id', $orderRow->id)->update([
            'status' => 'PROCESSING',
            'sla_deadline_at' => $slaDeadline,
            'updated_at' => now(),
        ]);

        $freshOrder = Order::with(['items', 'paymentInvoice'])->findOrFail((int) $orderRow->id);
        $deadlineFormatted = $slaDeadline->format('H:i:s');

        $this->chatModerationService->postOrderMessage(
            order: $freshOrder,
            sender: $sellerUser,
            rawMessage: "[ESCROW AKTIF • SLA {$slaMinutes} MENIT] Dana pembeli diamankan di rekening bersama NexaTrade. Penjual wajib menyelesaikan pengiriman sebelum pukul {$deadlineFormatted} WIB. Jangan melakukan transaksi atau kontak di luar platform NexaTrade.",
            senderRoleOverride: 'system',
        );

        $this->notificationService->notify(
            userId: (int) $orderRow->seller_id,
            category: 'order',
            title: "Pesanan Baru Masuk (SLA {$slaMinutes}m): {$freshOrder->order_code}",
            body: "Segera proses pengiriman sebelum pukul {$deadlineFormatted} WIB agar metrik SLA tetap terjaga.",
            actionUrl: '/seller/orders',
            refType: 'order',
            refId: (int) $freshOrder->id,
        );

        return $freshOrder;
    }
}
