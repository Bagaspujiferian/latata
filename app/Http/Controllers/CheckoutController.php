<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\CheckoutSplitOrderService;
use App\Services\FlashSaleService;
use App\Services\MidtransWebhookService;
use App\Services\VoucherAccountingService;
use App\Services\WalletPinService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutSplitOrderService $checkoutService,
        private readonly MidtransWebhookService $midtransWebhookService,
        private readonly VoucherAccountingService $voucherService,
        private readonly FlashSaleService $flashSaleService,
        private readonly WalletPinService $walletPinService,
    ) {}

    public function cart(Request $request): View
    {
        $user = $this->requireUser($request)->loadMissing('wallet');
        [$cartItems, $groupedPreview, $subtotal] = $this->buildCartBreakdown($user->id);

        $walletAvailable = (float) ($user->wallet->available_balance ?? 0);
        $gatewayFees = config('nexatrade.gateway_fees');
        $now = now();

        $availablePlatformVouchers = Voucher::where('issuer_type', 'platform')
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->whereColumn('quota_used', '<', 'quota_total')
            ->orderByDesc('discount_value')
            ->get();

        $sellerIdsInCart = array_values(array_unique(array_map(
            static fn (array $g): int => (int) $g['seller_id'],
            $groupedPreview,
        )));

        $availableSellerVouchers = empty($sellerIdsInCart)
            ? collect()
            : Voucher::where('issuer_type', 'seller')
                ->whereIn('seller_id', $sellerIdsInCart)
                ->where('is_active', true)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->whereColumn('quota_used', '<', 'quota_total')
                ->orderByDesc('discount_value')
                ->get()
                ->groupBy('seller_id');

        return view('checkout.cart', [
            'cartItems' => $cartItems,
            'groupedPreview' => $groupedPreview,
            'subtotal' => round($subtotal, 2),
            'walletAvailable' => $walletAvailable,
            'gatewayFees' => $gatewayFees,
            'availablePlatformVouchers' => $availablePlatformVouchers,
            'availableSellerVouchers' => $availableSellerVouchers,
        ]);
    }

    public function previewVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'seller_voucher_codes' => ['nullable', 'array'],
        ]);

        $user = $this->requireUser($request);
        [, $groupedPreview] = $this->buildCartBreakdown($user->id);

        if (empty($groupedPreview)) {
            return response()->json([
                'ok' => false,
                'message' => 'Keranjang belanja Anda masih kosong.',
            ], 422);
        }

        // If previewing a platform voucher while seller vouchers are already selected, reduce net_basis first for accurate preview.
        $sellerCodes = is_array($validated['seller_voucher_codes'] ?? null) ? $validated['seller_voucher_codes'] : [];
        $codeUpper = strtoupper(trim($validated['code']));
        $targetVoucher = Voucher::where('code', $codeUpper)->first();

        if ($targetVoucher && $targetVoucher->issuer_type === 'platform' && ! empty($sellerCodes)) {
            foreach ($sellerCodes as $sid => $sCode) {
                $cleanSCode = strtoupper(trim((string) $sCode));
                if ($cleanSCode === '') {
                    continue;
                }
                try {
                    $sPreview = $this->voucherService->previewVoucherDiscount($cleanSCode, $user->id, $groupedPreview);
                    if (($sPreview['issuer_type'] ?? '') === 'seller') {
                        foreach ($sPreview['allocations'] as $idx => $alloc) {
                            if (isset($groupedPreview[$idx])) {
                                $groupedPreview[$idx]['net_basis'] = max(0.0, round(
                                    (float) ($groupedPreview[$idx]['net_basis'] ?? $groupedPreview[$idx]['gross_amount']) - (float) $alloc,
                                    2,
                                ));
                            }
                        }
                    }
                } catch (DomainException) {
                    // Ignore stale seller voucher during platform preview
                }
            }
        }

        try {
            $preview = $this->voucherService->previewVoucherDiscount($codeUpper, $user->id, $groupedPreview);

            return response()->json([
                'ok' => true,
                'preview' => $preview,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array{0: Collection<int, CartItem>, 1: list<array<string, mixed>>, 2: float}
     */
    private function buildCartBreakdown(int $userId): array
    {
        $cartItems = CartItem::with(['product.gameCategory.game', 'product.sellerProfile.operatingHours', 'product.images'])
            ->where('user_id', $userId)
            ->orderBy('id', 'asc')
            ->get();

        $groupedPreview = [];
        $subtotal = 0.00;

        foreach ($cartItems as $item) {
            $product = $item->product;
            $category = $product?->gameCategory;
            if (! $product || ! $category) {
                continue;
            }
            $method = $category->delivery_method;
            $feePercent = (float) $category->platform_fee_percent;

            $regularUnit = (float) $product->price;
            $effectiveUnit = $regularUnit;
            $isFlashPrice = false;
            $flashItem = $this->flashSaleService->getActiveFlashItemForProduct($product->id);
            if ($flashItem && $flashItem->remainingQuota() >= (int) $item->quantity && (int) $item->quantity <= (int) $flashItem->max_qty_per_user) {
                $effectiveUnit = (float) $flashItem->flash_price;
                $isFlashPrice = true;
            }

            $item->setAttribute('preview_unit_price', $effectiveUnit);
            $item->setAttribute('preview_original_price', $regularUnit);
            $item->setAttribute('preview_is_flash', $isFlashPrice);

            $lineSubtotal = round($effectiveUnit * (int) $item->quantity, 2);
            $lineCommission = floor($lineSubtotal * ($feePercent / 100));
            $subtotal += $lineSubtotal;

            $groupKey = $product->seller_id.':'.$method;
            if (! isset($groupedPreview[$groupKey])) {
                $groupedPreview[$groupKey] = [
                    'seller_id' => (int) $product->seller_id,
                    'store_name' => $product->sellerProfile->store_name ?? 'Toko NexaTrade',
                    'is_store_open' => $product->sellerProfile?->isCurrentlyOpen() ?? true,
                    'is_suspended' => (bool) ($product->sellerProfile->is_suspended ?? false),
                    'is_sla_10m_badge' => $product->sellerProfile->is_sla_10m_badge ?? false,
                    'delivery_method' => $method,
                    'gross_amount' => 0.00,
                    'net_basis' => 0.00,
                    'platform_commission' => 0.00,
                    'seller_escrow_amount' => 0.00,
                    'items' => [],
                ];
            }

            $groupedPreview[$groupKey]['items'][] = $item;
            $groupedPreview[$groupKey]['gross_amount'] += $lineSubtotal;
            $groupedPreview[$groupKey]['net_basis'] += $lineSubtotal;
            $groupedPreview[$groupKey]['platform_commission'] += $lineCommission;
            $groupedPreview[$groupKey]['seller_escrow_amount'] = $groupedPreview[$groupKey]['gross_amount'] - $groupedPreview[$groupKey]['platform_commission'];
        }

        return [$cartItems, array_values($groupedPreview), round($subtotal, 2)];
    }

    public function addToCart(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->requireUser($request);
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'game_inputs' => ['nullable', 'array'],
            'verified_game_nickname' => ['nullable', 'string', 'max:100'],
            'action_mode' => ['nullable', 'in:cart,instant_buy'],
            'payment_mode' => ['nullable', 'in:gateway_only,wallet_full,hybrid_wallet_gateway'],
            'payment_channel' => ['nullable', 'string', 'max:40'],
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
            'wallet_pin' => ['nullable', 'string', 'max:6'],
        ]);

        $product = Product::with(['gameCategory', 'sellerProfile'])->findOrFail((int) $validated['product_id']);

        if ((int) $product->seller_id === (int) $user->id) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Anda tidak dapat membeli produk dari toko Anda sendiri.'], 422);
            }

            return back()->with('error', 'Anda tidak dapat membeli produk dari toko Anda sendiri.');
        }

        $inputs = $validated['game_inputs'] ?? [];

        if (($validated['action_mode'] ?? 'cart') === 'instant_buy') {
            try {
                $instantPaymentMode = $validated['payment_mode'] ?? 'wallet_full';
                if ($instantPaymentMode !== 'gateway_only') {
                    $this->walletPinService->verifyPinOrFail($user->id, $request->input('wallet_pin'), true);
                }

                $invoice = $this->checkoutService->checkout(
                    buyerId: $user->id,
                    paymentMode: $instantPaymentMode,
                    paymentChannel: $validated['payment_channel'] ?? 'qris',
                    requestedWalletAmount: isset($validated['wallet_amount']) ? (float) $validated['wallet_amount'] : null,
                    explicitItems: [[
                        'product_id' => $product->id,
                        'quantity' => (int) $validated['quantity'],
                        'buyer_game_inputs' => $inputs,
                        'verified_game_nickname' => $validated['verified_game_nickname'] ?? null,
                    ]],
                    clearCart: false,
                );

                if ($invoice->status === 'PAID') {
                    $firstOrder = $invoice->orders->first();
                    if ($firstOrder) {
                        return redirect()->route('buyer.orders.show', $firstOrder->order_code)
                            ->with('success', "Beli Instan lunas 0-detik! Pesanan {$firstOrder->order_code} langsung diproses.");
                    }

                    return redirect()->route('buyer.orders.index')
                        ->with('success', 'Beli Instan lunas 0-detik! Pesanan Anda langsung diproses.');
                }

                return redirect()->route('invoices.show', $invoice->invoice_code)
                    ->with('success', "Tagihan {$invoice->invoice_code} siap dibayar.");
            } catch (DomainException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        }

        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => (int) $validated['quantity'],
            'buyer_game_inputs' => ! empty($inputs) ? $inputs : null,
            'verified_game_nickname' => $validated['verified_game_nickname'] ?? null,
        ]);

        return redirect()->route('cart.index')
            ->with('success', "{$product->title} ditambahkan ke Keranjang Multi-Toko.");
    }

    public function updateCartItem(Request $request, CartItem $cartItem): RedirectResponse
    {
        if ((int) $cartItem->user_id !== (int) $this->requireUser($request)->id) {
            abort(403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $cartItem->update(['quantity' => (int) $validated['quantity']]);

        return back()->with('success', 'Jumlah keranjang diperbarui.');
    }

    public function removeCartItem(Request $request, CartItem $cartItem): RedirectResponse
    {
        if ((int) $cartItem->user_id !== (int) $this->requireUser($request)->id) {
            abort(403);
        }

        $cartItem->delete();

        return back()->with('success', 'Item dihapus dari keranjang.');
    }

    public function processCheckout(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->requireUser($request);
        $validated = $request->validate([
            'payment_mode' => ['required', 'in:gateway_only,wallet_full,hybrid_wallet_gateway'],
            'payment_channel' => ['nullable', 'string', 'max:40'],
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
            'platform_voucher_code' => ['nullable', 'string', 'max:40'],
            'seller_voucher_codes' => ['nullable', 'array'],
            'seller_voucher_codes.*' => ['nullable', 'string', 'max:40'],
            'wallet_pin' => ['nullable', 'string', 'max:6'],
            'expected_grand_total' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Defense-in-depth Self-Dealing Guard before service transaction
        $hasOwnProductInCart = CartItem::where('user_id', $user->id)
            ->whereHas('product', fn ($q) => $q->where('seller_id', $user->id))
            ->exists();
        if ($hasOwnProductInCart) {
            $msg = 'Anda tidak dapat membeli produk dari toko Anda sendiri.';
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $sellerVoucherCodes = [];
        foreach ($validated['seller_voucher_codes'] ?? [] as $sid => $code) {
            $clean = trim((string) $code);
            if ($clean !== '') {
                $sellerVoucherCodes[(int) $sid] = $clean;
            }
        }

        try {
            if ($validated['payment_mode'] !== 'gateway_only') {
                $this->walletPinService->verifyPinOrFail($user->id, $request->input('wallet_pin'), true);
            }

            $invoice = $this->checkoutService->checkout(
                buyerId: $user->id,
                paymentMode: $validated['payment_mode'],
                paymentChannel: $validated['payment_channel'] ?? 'qris',
                requestedWalletAmount: isset($validated['wallet_amount']) ? (float) $validated['wallet_amount'] : null,
                explicitItems: null,
                clearCart: true,
                platformVoucherCode: filled($validated['platform_voucher_code'] ?? null) ? trim((string) $validated['platform_voucher_code']) : null,
                sellerVoucherCodes: $sellerVoucherCodes,
                expectedGrandTotal: isset($validated['expected_grand_total']) ? (float) $validated['expected_grand_total'] : null,
            );

            if ($invoice->status === 'PAID') {
                return redirect()->route('buyer.orders.index')
                    ->with('success', "Tagihan {$invoice->invoice_code} LUNAS instan via Saldo Dompet! ".$invoice->orders->count().' pesanan lintas toko aktif.');
            }

            return redirect()->route('invoices.show', $invoice->invoice_code)
                ->with('success', "Tagihan induk {$invoice->invoice_code} berhasil dibuat untuk ".$invoice->orders->count().' toko.');
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function showInvoice(Request $request, string $invoiceCode): View
    {
        $user = $this->requireUser($request);
        $invoice = PaymentInvoice::with(['orders.items.product', 'orders.sellerProfile', 'walletMutations'])
            ->where('invoice_code', $invoiceCode)
            ->firstOrFail();

        if ((int) $invoice->buyer_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        return view('checkout.invoice', compact('invoice'));
    }

    public function simulateMidtransPay(Request $request, string $invoiceCode): RedirectResponse
    {
        $user = $this->requireUser($request);
        $invoice = PaymentInvoice::where('invoice_code', $invoiceCode)->firstOrFail();

        if ((int) $invoice->buyer_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');
        $signature = $this->midtransWebhookService->generateSignature($invoice->invoice_code, '200', $grossStr);

        $result = $this->midtransWebhookService->handleNotification([
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-SIM-'.strtoupper(Str::random(10)),
            'signature_key' => $signature,
        ]);

        if (($result['http_status'] ?? 500) !== 200) {
            return back()->with('error', $result['message'] ?? 'Simulasi Midtrans gagal.');
        }

        if ($result['zombie_recovered'] ?? false) {
            return redirect()->route('invoices.show', $invoice->invoice_code)
                ->with('success', 'Zombie Payment Recovery Berhasil! Karena invoice sudah EXPIRED, dana Rp '.number_format((float) $invoice->grand_total, 0, ',', '.').' langsung diamankan ke Saldo Dompet Anda.');
        }

        $firstOrder = $invoice->orders()->first();
        if ($invoice->orders()->count() === 1 && $firstOrder) {
            return redirect()->route('buyer.orders.show', $firstOrder->order_code)
                ->with('success', 'Webhook Midtrans SHA-512 terverifikasi! Dana escrow masuk & pesanan langsung didispatch.');
        }

        return redirect()->route('buyer.orders.index')
            ->with('success', 'Webhook Midtrans SHA-512 terverifikasi! Semua Split-Order berhasil didispatch.');
    }
}
