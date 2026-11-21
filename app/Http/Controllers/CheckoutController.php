<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Services\CheckoutSplitOrderService;
use App\Services\MidtransWebhookService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutSplitOrderService $checkoutService,
        private readonly MidtransWebhookService $midtransWebhookService,
    ) {}

    public function cart(Request $request): View
    {
        $user = $request->user();
        $cartItems = CartItem::with(['product.gameCategory.game', 'product.sellerProfile'])
            ->where('user_id', $user->id)
            ->orderBy('id', 'asc')
            ->get();

        // Build live Split-Order Preview grouped by (seller_id, delivery_method) matching backend exact math.
        $groupedPreview = [];
        $subtotal = 0.00;

        foreach ($cartItems as $item) {
            if (! $item->product) {
                continue;
            }
            $product = $item->product;
            $method = $product->gameCategory->delivery_method;
            $feePercent = (float) $product->gameCategory->platform_fee_percent;
            $lineSubtotal = round((float) $product->price * (int) $item->quantity, 2);
            $lineCommission = floor($lineSubtotal * ($feePercent / 100));
            $subtotal += $lineSubtotal;

            $groupKey = $product->seller_id.':'.$method;
            if (! isset($groupedPreview[$groupKey])) {
                $groupedPreview[$groupKey] = [
                    'seller_id' => $product->seller_id,
                    'store_name' => $product->sellerProfile?->store_name ?? 'Toko NexaTrade',
                    'is_store_open' => $product->sellerProfile?->is_store_open ?? true,
                    'is_sla_10m_badge' => $product->sellerProfile?->is_sla_10m_badge ?? false,
                    'delivery_method' => $method,
                    'gross_amount' => 0.00,
                    'platform_commission' => 0.00,
                    'seller_escrow_amount' => 0.00,
                    'items' => [],
                ];
            }

            $groupedPreview[$groupKey]['items'][] = $item;
            $groupedPreview[$groupKey]['gross_amount'] += $lineSubtotal;
            $groupedPreview[$groupKey]['platform_commission'] += $lineCommission;
            $groupedPreview[$groupKey]['seller_escrow_amount'] = $groupedPreview[$groupKey]['gross_amount'] - $groupedPreview[$groupKey]['platform_commission'];
        }

        $walletAvailable = (float) ($user->wallet?->available_balance ?? 0);
        $gatewayFees = config('nexatrade.gateway_fees');

        return view('checkout.cart', [
            'cartItems' => $cartItems,
            'groupedPreview' => array_values($groupedPreview),
            'subtotal' => round($subtotal, 2),
            'walletAvailable' => $walletAvailable,
            'gatewayFees' => $gatewayFees,
        ]);
    }

    public function addToCart(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'game_inputs' => ['nullable', 'array'],
            'verified_game_nickname' => ['nullable', 'string', 'max:100'],
            'action_mode' => ['nullable', 'in:cart,instant_buy'],
            'payment_mode' => ['nullable', 'in:gateway_only,wallet_full,hybrid_wallet_gateway'],
            'payment_channel' => ['nullable', 'string', 'max:40'],
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::with(['gameCategory', 'sellerProfile'])->findOrFail((int) $validated['product_id']);

        if ((int) $product->seller_id === (int) $user->id) {
            return back()->with('error', 'Self-Dealing Guard Aktif: Anda dilarang membeli produk milik toko Anda sendiri.');
        }

        $inputs = $validated['game_inputs'] ?? [];

        if (($validated['action_mode'] ?? 'cart') === 'instant_buy') {
            try {
                $invoice = $this->checkoutService->checkout(
                    buyerId: $user->id,
                    paymentMode: $validated['payment_mode'] ?? 'wallet_full',
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

                    return redirect()->route('buyer.orders.show', $firstOrder->order_code)
                        ->with('success', "Beli Instan lunas 0-detik! Pesanan {$firstOrder->order_code} langsung diproses.");
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
        if ((int) $cartItem->user_id !== (int) $request->user()->id) {
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
        if ((int) $cartItem->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $cartItem->delete();

        return back()->with('success', 'Item dihapus dari keranjang.');
    }

    public function processCheckout(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'payment_mode' => ['required', 'in:gateway_only,wallet_full,hybrid_wallet_gateway'],
            'payment_channel' => ['nullable', 'string', 'max:40'],
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $invoice = $this->checkoutService->checkout(
                buyerId: $user->id,
                paymentMode: $validated['payment_mode'],
                paymentChannel: $validated['payment_channel'] ?? 'qris',
                requestedWalletAmount: isset($validated['wallet_amount']) ? (float) $validated['wallet_amount'] : null,
                explicitItems: null,
                clearCart: true,
            );

            if ($invoice->status === 'PAID') {
                return redirect()->route('buyer.orders.index')
                    ->with('success', "Tagihan {$invoice->invoice_code} LUNAS instan via Saldo Dompet! ".$invoice->orders->count().' pesanan lintas toko aktif.');
            }

            return redirect()->route('invoices.show', $invoice->invoice_code)
                ->with('success', "Tagihan induk {$invoice->invoice_code} berhasil dibuat untuk ".$invoice->orders->count().' toko.');
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function showInvoice(Request $request, string $invoiceCode): View
    {
        $user = $request->user();
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
        $user = $request->user();
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
