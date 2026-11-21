<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentInvoice;
use App\Models\WalletMutation;
use App\Models\WalletTopUp;
use App\Models\WarrantyClaim;
use App\Services\ChatModerationService;
use App\Services\OrderStateAndSlaService;
use App\Services\VaultEncryptionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerOrderController extends Controller
{
    public function __construct(
        private readonly OrderStateAndSlaService $orderStateService,
        private readonly VaultEncryptionService $vaultService,
        private readonly ChatModerationService $chatModerationService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $statusFilter = $request->string('status')->toString();

        $ordersQuery = Order::with(['items.product.gameCategory.game', 'sellerProfile', 'paymentInvoice', 'review'])
            ->where('buyer_id', $user->id)
            ->orderByDesc('id');

        if ($statusFilter !== '') {
            $ordersQuery->where('status', $statusFilter);
        }

        $orders = $ordersQuery->get();

        $unpaidInvoices = PaymentInvoice::with('orders.sellerProfile')
            ->where('buyer_id', $user->id)
            ->where('status', 'UNPAID')
            ->orderByDesc('id')
            ->get();

        $statusCounts = Order::where('buyer_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('buyer.orders.index', compact('orders', 'unpaidInvoices', 'statusFilter', 'statusCounts'));
    }

    public function show(Request $request, string $orderCode): View
    {
        $user = $request->user();
        $order = Order::with([
            'items.product.gameCategory.game',
            'items.vault',
            'items.flashSaleItem',
            'sellerProfile',
            'seller',
            'buyer',
            'paymentInvoice',
            'messages.sender',
            'review',
            'walletMutations',
            'warranty.claims.resolver',
        ])->where('order_code', $orderCode)->firstOrFail();

        if ((int) $order->buyer_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $reasonLabels = WarrantyClaim::REASON_LABELS;

        return view('buyer.orders.show', compact('order', 'reasonLabels'));
    }

    public function decryptVaultItem(Request $request, string $orderCode, OrderItem $orderItem): JsonResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();

        try {
            $plaintext = $this->vaultService->decryptForAuthorizedBuyer($order, $orderItem, $request->user()->id);

            return response()->json([
                'ok' => true,
                'order_item_id' => $orderItem->id,
                'plaintext_payload' => $plaintext,
                'blind_hash_prefix' => $orderItem->vault?->payload_blind_hash
                    ? substr($orderItem->vault->payload_blind_hash, 0, 16).'...'
                    : null,
                'decrypted_at' => now()->toIso8601String(),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 403);
        }
    }

    public function claimSlaRefund(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();

        try {
            $this->orderStateService->buyerClaimSlaBreachRefund($order->id, $request->user()->id);

            return back()->with('success', 'Refund Pelanggaran SLA Berhasil! 100% dana pesanan telah masuk instan ke Saldo Aktif Anda.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function confirmComplete(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $validated = $request->validate([
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->orderStateService->buyerConfirmOrderCompleted(
                orderId: $order->id,
                buyerId: $request->user()->id,
                rating: isset($validated['rating']) ? (int) $validated['rating'] : null,
                comment: $validated['comment'] ?? null,
            );

            return back()->with('success', 'Pesanan selesai! Dana escrow telah dilepaskan ke penjual dan ulasan Anda tercatat.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function openDispute(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'attachment_proof_url' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->orderStateService->buyerOpenDispute(
                orderId: $order->id,
                buyerId: $request->user()->id,
                reason: $validated['reason'],
                proofUrl: $validated['attachment_proof_url'] ?? null,
            );

            return back()->with('success', 'Komplain dibuka & Escrow dibekukan! Timer 24 jam dihentikan hingga Admin Arbitrase memutus sengketa.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendMessage(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $user = $request->user();

        if ((int) $order->buyer_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:1000'],
            'attachment_proof_url' => ['nullable', 'string', 'max:255'],
        ]);

        $msg = $this->chatModerationService->postOrderMessage(
            order: $order,
            sender: $user,
            rawMessage: $validated['message'],
            attachmentProofUrl: $validated['attachment_proof_url'] ?? null,
        );

        if ($msg->is_flagged_bypass) {
            return back()->with('warning', "Peringatan Anti-Bypass: Pola [{$msg->detected_violation_type}] otomatis disensor untuk melindungi dana escrow Anda.");
        }

        return back()->with('success', 'Pesan terkirim di ruang chat pesanan.');
    }

    public function wallet(Request $request): View
    {
        $user = $request->user()->load('wallet');
        $mutations = WalletMutation::with(['order', 'paymentInvoice'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $topups = WalletTopUp::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('buyer.wallet', compact('user', 'mutations', 'topups'));
    }
}
