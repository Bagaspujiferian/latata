<?php

namespace App\Services;

use App\Exceptions\InsufficientVaultStockException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidGameInputException;
use App\Exceptions\SelfDealingException;
use App\Exceptions\StoreClosedException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Support\StateTransitionGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutSplitOrderService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly VaultEncryptionService $vaultService,
        private readonly OrderFulfillmentDispatcher $fulfillmentDispatcher,
    ) {}

    /**
     * Checkout either from user's cart OR from an explicit single/multi instant-buy item array.
     *
     * @param  list<array{product_id: int, quantity: int, buyer_game_inputs?: array<string, mixed>, verified_game_nickname?: ?string}>|null  $explicitItems
     */
    public function checkout(
        int $buyerId,
        string $paymentMode,
        string $paymentChannel = 'qris',
        ?float $requestedWalletAmount = null,
        ?array $explicitItems = null,
        bool $clearCart = true,
    ): PaymentInvoice {
        if (! in_array($paymentMode, ['gateway_only', 'wallet_full', 'hybrid_wallet_gateway'], true)) {
            throw new DomainException("Mode pembayaran tidak valid: [{$paymentMode}].");
        }

        return DB::transaction(function () use (
            $buyerId,
            $paymentMode,
            $paymentChannel,
            $requestedWalletAmount,
            $explicitItems,
            $clearCart,
        ): PaymentInvoice {
            $rawItems = $this->resolveItems($buyerId, $explicitItems);

            if (empty($rawItems)) {
                throw new DomainException('Keranjang belanja masih kosong.');
            }

            // DEADLOCK PREVENTION LAW: Sort all unique product IDs ascending BEFORE lockForUpdate().
            $sortedProductIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item['product_id'],
                $rawItems,
            )));
            sort($sortedProductIds, SORT_NUMERIC);

            $lockedProductRows = DB::table('products')
                ->whereIn('id', $sortedProductIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $products = Product::with(['gameCategory.game', 'sellerProfile.operatingHours'])
                ->whereIn('id', $sortedProductIds)
                ->get()
                ->keyBy('id');

            // Aggregate quantities per product_id so total stock checks are exact even if same product appears twice.
            $requestedQtyByProduct = [];
            foreach ($rawItems as $entry) {
                $pid = (int) $entry['product_id'];
                $qty = max(1, (int) $entry['quantity']);
                $requestedQtyByProduct[$pid] = ($requestedQtyByProduct[$pid] ?? 0) + $qty;
            }

            // Pre-validate every item: active, self-dealing guard, store open guard, dynamic inputs, min/max, stock.
            $validatedItems = [];
            foreach ($rawItems as $entry) {
                $pid = (int) $entry['product_id'];
                $qty = (int) $entry['quantity'];
                $lockedRow = $lockedProductRows->get($pid);
                $product = $products->get($pid);

                if (! $lockedRow || ! $product || ! $lockedRow->is_active || $lockedRow->deleted_at !== null) {
                    throw new DomainException("Produk #{$pid} tidak ditemukan atau sedang nonaktif.");
                }

                // SELF-DEALING GUARD (Anti Wash-Trading Law #8)
                if ((int) $lockedRow->seller_id === $buyerId) {
                    throw new SelfDealingException("Dilarang membeli produk milik toko Anda sendiri ({$product->title}).");
                }

                $category = $product->gameCategory;
                $deliveryMethod = $category->delivery_method;

                // STORE OPEN & AUTO-SCHEDULE GUARD: manual_trade blocked when closed; instant_vault & api_h2h stay 24/7.
                if ($deliveryMethod === 'manual_trade' && $product->sellerProfile && ! $product->sellerProfile->isOpenForManualTradeNow()) {
                    throw new StoreClosedException(
                        "Toko {$product->sellerProfile->store_name} sedang TUTUP atau di luar jam operasional untuk produk manual trade ({$product->title}). Produk Brankas Instan & H2H tetap tersedia 24/7."
                    );
                }

                if ($qty < (int) $lockedRow->min_order || $qty > (int) $lockedRow->max_order) {
                    throw new DomainException(
                        "Jumlah pesanan untuk {$product->title} harus antara {$lockedRow->min_order} sampai {$lockedRow->max_order}."
                    );
                }

                $inputs = is_array($entry['buyer_game_inputs'] ?? null) ? $entry['buyer_game_inputs'] : [];
                $this->validateDynamicGameInputs($category->required_inputs_schema ?? [], $inputs, $product->title);

                if ($deliveryMethod !== 'instant_vault' && (int) $lockedRow->stock < $requestedQtyByProduct[$pid]) {
                    throw new InsufficientVaultStockException(
                        "Stok {$product->title} tidak mencukupi (tersedia {$lockedRow->stock}, diminta {$requestedQtyByProduct[$pid]})."
                    );
                }

                $validatedItems[] = [
                    'product' => $product,
                    'locked_row' => $lockedRow,
                    'delivery_method' => $deliveryMethod,
                    'fee_percent' => (float) $category->platform_fee_percent,
                    'quantity' => $qty,
                    'unit_price' => (float) $lockedRow->price,
                    'buyer_game_inputs' => $inputs,
                    'verified_game_nickname' => $entry['verified_game_nickname'] ?? null,
                ];
            }

            // Lock vault rows atomically using FOR UPDATE SKIP LOCKED for instant_vault products (sorted by product_id ASC).
            $allocatedVaultsByIndex = [];
            $vaultProductsToSync = [];

            foreach ($validatedItems as $idx => $item) {
                if ($item['delivery_method'] !== 'instant_vault') {
                    continue;
                }

                $productId = (int) $item['product']->id;
                $qty = (int) $item['quantity'];

                $vaultQuery = DB::table('product_vaults')
                    ->where('product_id', $productId)
                    ->where('status', 'available')
                    ->orderBy('id', 'asc')
                    ->limit($qty);

                $driver = DB::connection()->getDriverName();
                if ($driver === 'sqlite') {
                    $lockedVaults = $vaultQuery->lockForUpdate()->get();
                } else {
                    $lockedVaults = $vaultQuery->lock('FOR UPDATE SKIP LOCKED')->get();
                }

                if ($lockedVaults->count() < $qty) {
                    throw new InsufficientVaultStockException(
                        "Stok brankas AES-256 untuk {$item['product']->title} tidak mencukupi (tersedia {$lockedVaults->count()} kode, diminta {$qty})."
                    );
                }

                $allocatedVaultsByIndex[$idx] = $lockedVaults;
                $vaultProductsToSync[$productId] = true;
            }

            // Group validated items into Split-Orders per (seller_id, delivery_method).
            $groupedOrders = [];
            foreach ($validatedItems as $idx => $item) {
                $groupKey = $item['product']->seller_id.':'.$item['delivery_method'];
                if (! isset($groupedOrders[$groupKey])) {
                    $groupedOrders[$groupKey] = [
                        'seller_id' => (int) $item['product']->seller_id,
                        'delivery_method' => $item['delivery_method'],
                        'items' => [],
                    ];
                }
                $item['vaults'] = $allocatedVaultsByIndex[$idx] ?? collect();
                $groupedOrders[$groupKey]['items'][] = $item;
            }

            // Compute zero-discrepancy financial totals.
            $subtotalAmount = 0.00;
            $preparedOrders = [];

            foreach ($groupedOrders as $group) {
                $orderGross = 0.00;
                $orderCommission = 0.00;
                $maxSlaMinutes = 10;
                $mergedInputs = [];
                $nickname = null;

                foreach ($group['items'] as $item) {
                    $lineSubtotal = round($item['unit_price'] * $item['quantity'], 2);
                    $lineCommission = floor($lineSubtotal * ($item['fee_percent'] / 100));

                    $orderGross += $lineSubtotal;
                    $orderCommission += $lineCommission;
                    $maxSlaMinutes = max($maxSlaMinutes, (int) $item['locked_row']->sla_target_minutes);

                    if (! empty($item['buyer_game_inputs'])) {
                        $mergedInputs = array_merge($mergedInputs, $item['buyer_game_inputs']);
                    }
                    if (! empty($item['verified_game_nickname'])) {
                        $nickname = $item['verified_game_nickname'];
                    }
                }

                $orderGross = round($orderGross, 2);
                $orderCommission = round($orderCommission, 2);
                $sellerEscrow = round($orderGross - $orderCommission, 2);
                $subtotalAmount += $orderGross;

                $preparedOrders[] = [
                    'seller_id' => $group['seller_id'],
                    'delivery_method' => $group['delivery_method'],
                    'gross_amount' => $orderGross,
                    'platform_commission' => $orderCommission,
                    'seller_escrow_amount' => $sellerEscrow,
                    'sla_target_minutes' => $maxSlaMinutes,
                    'buyer_game_inputs' => ! empty($mergedInputs) ? $mergedInputs : null,
                    'verified_game_nickname' => $nickname,
                    'items' => $group['items'],
                ];
            }

            $subtotalAmount = round($subtotalAmount, 2);

            // Compute payment split: wallet_full vs hybrid_wallet_gateway vs gateway_only.
            $buyerWallet = DB::table('wallets')->where('user_id', $buyerId)->lockForUpdate()->first();
            $buyerAvailable = $buyerWallet ? (float) $buyerWallet->available_balance : 0.00;

            $walletUsedAmount = 0.00;
            $gatewayFee = 0.00;
            $resolvedChannel = $paymentChannel;

            if ($paymentMode === 'wallet_full') {
                if ($buyerAvailable < $subtotalAmount) {
                    throw new InsufficientWalletBalanceException(
                        'Saldo Dompet tidak mencukupi untuk pembayaran penuh (tersedia Rp '.number_format($buyerAvailable, 0, ',', '.').', tagihan Rp '.number_format($subtotalAmount, 0, ',', '.').').'
                    );
                }
                $walletUsedAmount = $subtotalAmount;
                $gatewayFee = 0.00;
                $resolvedChannel = 'wallet_full';
            } elseif ($paymentMode === 'hybrid_wallet_gateway') {
                if ($buyerAvailable <= 0) {
                    throw new InsufficientWalletBalanceException('Saldo Dompet Anda Rp 0 — gunakan pembayaran penuh via Midtrans Gateway.');
                }

                $desiredWallet = $requestedWalletAmount !== null && $requestedWalletAmount > 0
                    ? min($requestedWalletAmount, $buyerAvailable, $subtotalAmount - 1000)
                    : min($buyerAvailable, max(1000.00, floor($subtotalAmount * 0.5)));

                $desiredWallet = round($desiredWallet, 2);
                if ($desiredWallet <= 0 || $desiredWallet >= $subtotalAmount) {
                    throw new DomainException(
                        'Pembayaran Hibrida memerlukan potongan saldo lebih besar dari Rp 0 dan lebih kecil dari total belanja (gunakan Bayar Penuh Saldo jika saldo cukup 100%).'
                    );
                }

                $walletUsedAmount = $desiredWallet;
                $resolvedChannel = str_starts_with($paymentChannel, 'hybrid_wallet_')
                    ? $paymentChannel
                    : 'hybrid_wallet_'.$paymentChannel;
                $gatewayFee = (float) (config("nexatrade.gateway_fees.{$resolvedChannel}")
                    ?? config("nexatrade.gateway_fees.{$paymentChannel}")
                    ?? 1500.00);
            } else {
                $walletUsedAmount = 0.00;
                $resolvedChannel = in_array($paymentChannel, ['qris', 'bca_va', 'bri_va', 'gopay'], true)
                    ? $paymentChannel
                    : 'qris';
                $gatewayFee = (float) (config("nexatrade.gateway_fees.{$resolvedChannel}") ?? 1500.00);
            }

            $payableAfterWallet = round($subtotalAmount - $walletUsedAmount, 2);
            $grandTotal = round($payableAfterWallet + $gatewayFee, 2);

            $invoiceCode = 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $expiresAt = now()->addMinutes((int) config('nexatrade.invoice_expiry_minutes', 30));
            $snapToken = $grandTotal > 0
                ? 'SNAP-'.strtolower(hash('sha256', $invoiceCode.'|'.$buyerId.'|'.microtime(true)))
                : null;

            $invoice = PaymentInvoice::create([
                'invoice_code' => $invoiceCode,
                'buyer_id' => $buyerId,
                'subtotal_amount' => number_format($subtotalAmount, 2, '.', ''),
                'gateway_fee' => number_format($gatewayFee, 2, '.', ''),
                'wallet_used_amount' => number_format($walletUsedAmount, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'payment_channel' => $resolvedChannel,
                'snap_token' => $snapToken,
                'status' => 'UNPAID',
                'expires_at' => $expiresAt,
            ]);

            // Create child per-seller orders + immutable order_items snapshots + reserve vaults / decrement stock.
            $createdOrderIds = [];
            foreach ($preparedOrders as $seq => $orderData) {
                $orderCode = 'NXT-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)).($seq + 1);

                $order = Order::create([
                    'order_code' => $orderCode,
                    'payment_invoice_id' => $invoice->id,
                    'buyer_id' => $buyerId,
                    'seller_id' => $orderData['seller_id'],
                    'gross_amount' => number_format($orderData['gross_amount'], 2, '.', ''),
                    'platform_commission' => number_format($orderData['platform_commission'], 2, '.', ''),
                    'seller_escrow_amount' => number_format($orderData['seller_escrow_amount'], 2, '.', ''),
                    'delivery_method' => $orderData['delivery_method'],
                    'status' => 'WAITING_PAYMENT',
                    'buyer_game_inputs' => $orderData['buyer_game_inputs'],
                    'verified_game_nickname' => $orderData['verified_game_nickname'],
                    'sla_target_minutes_snapshot' => $orderData['sla_target_minutes'],
                ]);

                $createdOrderIds[] = (int) $order->id;

                foreach ($orderData['items'] as $item) {
                    $product = $item['product'];
                    $unitPrice = (float) $item['unit_price'];
                    $qty = (int) $item['quantity'];

                    if ($item['delivery_method'] === 'instant_vault') {
                        foreach ($item['vaults'] as $vaultRow) {
                            DB::table('product_vaults')
                                ->where('id', $vaultRow->id)
                                ->update([
                                    'status' => 'reserved',
                                    'reserved_order_id' => $order->id,
                                    'reserved_until' => $expiresAt,
                                    'updated_at' => now(),
                                ]);

                            OrderItem::create([
                                'order_id' => $order->id,
                                'product_id' => $product->id,
                                'product_vault_id' => $vaultRow->id,
                                'product_title_snapshot' => $product->title,
                                'sku_snapshot' => $product->sku_code,
                                'unit_price_snapshot' => number_format($unitPrice, 2, '.', ''),
                                'quantity' => 1,
                                'subtotal' => number_format($unitPrice, 2, '.', ''),
                            ]);
                        }
                    } else {
                        DB::table('products')
                            ->where('id', $product->id)
                            ->decrement('stock', $qty);

                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_vault_id' => null,
                            'product_title_snapshot' => $product->title,
                            'sku_snapshot' => $product->sku_code,
                            'unit_price_snapshot' => number_format($unitPrice, 2, '.', ''),
                            'quantity' => $qty,
                            'subtotal' => number_format(round($unitPrice * $qty, 2), 2, '.', ''),
                        ]);
                    }
                }
            }

            // Vault-Driven Stock Integrity Law (#5): re-sync products.stock atomically with available vault count.
            foreach (array_keys($vaultProductsToSync) as $vaultProductId) {
                $this->vaultService->syncVaultStockWithinLock((int) $vaultProductId);
            }

            // Clear user's cart if checkout came from cart.
            if ($clearCart && $explicitItems === null) {
                CartItem::where('user_id', $buyerId)->delete();
            }

            // Execute wallet deduction & instant settlement if wallet_full, or HYBRID_WALLET_HOLD if hybrid.
            if ($paymentMode === 'wallet_full') {
                $this->ledgerService->deductBuyerWalletForCheckout(
                    buyerId: $buyerId,
                    invoiceId: $invoice->id,
                    amount: $walletUsedAmount,
                    invoiceCode: $invoice->invoice_code,
                    isHybrid: false,
                );

                StateTransitionGuard::assertInvoiceTransition($invoice->status, 'PAID', "Full Wallet {$invoice->invoice_code}");

                DB::table('payment_invoices')->where('id', $invoice->id)->update([
                    'status' => 'PAID',
                    'midtrans_transaction_id' => 'WALLET-'.$invoice->invoice_code,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

                // Lock child orders in ascending ID order and dispatch fulfillment atomically.
                sort($createdOrderIds, SORT_NUMERIC);
                $lockedOrders = Order::whereIn('id', $createdOrderIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($lockedOrders as $childOrder) {
                    StateTransitionGuard::assertOrderTransition($childOrder->status, 'PAID_ESCROW', "Full Wallet {$childOrder->order_code}");

                    DB::table('orders')->where('id', $childOrder->id)->update([
                        'status' => 'PAID_ESCROW',
                        'updated_at' => now(),
                    ]);

                    $this->ledgerService->holdSellerEscrow(
                        sellerId: (int) $childOrder->seller_id,
                        orderId: (int) $childOrder->id,
                        amount: (float) $childOrder->seller_escrow_amount,
                        orderCode: $childOrder->order_code,
                    );

                    $this->fulfillmentDispatcher->dispatch($childOrder->fresh());
                }
            } elseif ($paymentMode === 'hybrid_wallet_gateway' && $walletUsedAmount > 0) {
                $this->ledgerService->deductBuyerWalletForCheckout(
                    buyerId: $buyerId,
                    invoiceId: $invoice->id,
                    amount: $walletUsedAmount,
                    invoiceCode: $invoice->invoice_code,
                    isHybrid: true,
                );
            }

            return $invoice->fresh(['orders.items', 'orders.sellerProfile']);
        });
    }

    /**
     * @param  list<array<string, mixed>>|null  $explicitItems
     * @return list<array<string, mixed>>
     */
    private function resolveItems(int $buyerId, ?array $explicitItems): array
    {
        if ($explicitItems !== null) {
            return $explicitItems;
        }

        return CartItem::where('user_id', $buyerId)
            ->orderBy('id', 'asc')
            ->get()
            ->map(static fn (CartItem $cart): array => [
                'product_id' => (int) $cart->product_id,
                'quantity' => (int) $cart->quantity,
                'buyer_game_inputs' => $cart->buyer_game_inputs ?? [],
                'verified_game_nickname' => $cart->verified_game_nickname,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, mixed>  $inputs
     */
    private function validateDynamicGameInputs(array $schema, array $inputs, string $productTitle): void
    {
        foreach ($schema as $field) {
            $key = (string) ($field['key'] ?? '');
            $label = (string) ($field['label'] ?? $key);
            $required = (bool) ($field['required'] ?? false);
            $type = (string) ($field['type'] ?? 'text');

            $value = isset($inputs[$key]) ? trim((string) $inputs[$key]) : '';

            if ($required && $value === '') {
                throw new InvalidGameInputException("Kolom [{$label}] wajib diisi untuk produk {$productTitle}.");
            }

            if ($value !== '' && $type === 'number' && ! preg_match('/^\d+$/', $value)) {
                throw new InvalidGameInputException("Kolom [{$label}] pada {$productTitle} harus berupa angka.");
            }
        }
    }
}
