<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentWebhookLog;
use App\Models\WalletMutation;
use App\Models\WithdrawalRequest;
use App\Services\ChaosLabRunnerService;
use App\Services\ChatModerationService;
use App\Services\OrderStateAndSlaService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminChaosLabController extends Controller
{
    public function __construct(
        private readonly OrderStateAndSlaService $orderStateService,
        private readonly ChatModerationService $chatModerationService,
        private readonly ChaosLabRunnerService $chaosLabService,
    ) {}

    public function disputes(): View
    {
        $disputedOrders = Order::with([
            'items.product.gameCategory.game',
            'buyer.wallet',
            'seller.wallet',
            'sellerProfile',
            'messages.sender',
            'paymentInvoice',
        ])
            ->where('status', 'DISPUTED')
            ->orderBy('updated_at', 'asc')
            ->get();

        $resolvedDisputes = Order::with(['buyer', 'sellerProfile', 'items'])
            ->whereIn('status', ['COMPLETED', 'REFUNDED'])
            ->whereHas('messages', fn ($q) => $q->where('sender_role', 'admin'))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return view('admin.disputes', compact('disputedOrders', 'resolvedDisputes'));
    }

    public function resolveDispute(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $validated = $request->validate([
            'verdict' => ['required', 'in:RELEASE_TO_SELLER,REFUND_TO_BUYER,SPLIT_SETTLEMENT'],
            'admin_notes' => ['required', 'string', 'min:5', 'max:500'],
            'buyer_refund_percent' => ['required_if:verdict,SPLIT_SETTLEMENT', 'nullable', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $buyerPct = isset($validated['buyer_refund_percent']) ? (int) $validated['buyer_refund_percent'] : null;

            $this->orderStateService->adminResolveDispute(
                orderId: $order->id,
                adminId: $request->user()->id,
                verdict: $validated['verdict'],
                adminNotes: $validated['admin_notes'],
                buyerRefundPercent: $buyerPct,
            );

            $label = match ($validated['verdict']) {
                'RELEASE_TO_SELLER' => 'Menangkan Penjual (ESCROW_RELEASE)',
                'SPLIT_SETTLEMENT' => "Split Settlement ({$buyerPct}% Pembeli / ".(100 - (int) $buyerPct).'% Penjual)',
                default => 'Menangkan Pembeli (ESCROW_REFUND_DEBIT + BUYER_REFUND_CREDIT)',
            };

            return back()->with('success', "Putusan Sengketa {$order->order_code} dieksekusi: {$label}.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendAdminMessage(Request $request, string $orderCode): RedirectResponse
    {
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        $this->chatModerationService->postOrderMessage(
            order: $order,
            sender: $request->user(),
            rawMessage: $validated['message'],
            senderRoleOverride: 'admin',
        );

        return back()->with('success', 'Pesan mediasi Admin dikirim ke ruang sengketa.');
    }

    public function withdrawals(): View
    {
        $withdrawals = WithdrawalRequest::with(['user.sellerProfile', 'user.wallet', 'processor'])
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return view('admin.withdrawals', compact('withdrawals'));
    }

    public function approveWithdrawal(Request $request, int $id): RedirectResponse
    {
        try {
            $wd = $this->orderStateService->approveWithdrawal($id, $request->user()->id);

            return back()->with('success', "Penarikan {$wd->withdraw_code} disetujui & ditandai PAID (Rp ".number_format((float) $wd->net_payout, 0, ',', '.').').');
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function rejectWithdrawal(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        try {
            $wd = $this->orderStateService->rejectWithdrawal($id, $request->user()->id, $validated['rejection_reason']);

            return back()->with('success', "Penarikan {$wd->withdraw_code} ditolak. Saldo Rp ".number_format((float) $wd->amount, 0, ',', '.').' otomatis dikembalikan via WITHDRAW_REJECT_REFUND.');
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function chaosLab(): View
    {
        $recentWebhooks = PaymentWebhookLog::orderByDesc('id')->limit(15)->get();
        $recentMutations = WalletMutation::with('user')->orderByDesc('id')->limit(15)->get();

        $systemTelemetry = [
            'total_escrow_locked' => (float) \App\Models\Wallet::sum('pending_balance'),
            'total_available_liquidity' => (float) \App\Models\Wallet::sum('available_balance'),
            'active_disputes' => Order::where('status', 'DISPUTED')->count(),
            'pending_withdrawals' => WithdrawalRequest::where('status', 'PENDING')->count(),
            'webhook_logs_count' => PaymentWebhookLog::count(),
            'ledger_rows_count' => WalletMutation::count(),
        ];

        $scenarios = [
            [
                'id' => 1,
                'code' => 'TEST-01',
                'badge' => 'CRYPTOGRAPHIC WEBHOOK',
                'title' => 'Valid Midtrans SHA-512 Webhook & Split-Order Settlement',
                'summary' => 'Membuat invoice multi-toko UNPAID, menandatangani SHA-512 asli, memverifikasi ESCROW_HOLD 2 toko, dekripsi AES-256 instant_vault, dan aktivasi SLA manual_trade.',
            ],
            [
                'id' => 2,
                'code' => 'TEST-02',
                'badge' => 'IDEMPOTENCY SHIELD',
                'title' => '5x Duplicate Webhook Replay Attack',
                'summary' => 'Menembakkan 5 webhook settlement identik berturut-turut untuk membuktikan payment_webhook_logs memblokir 4 duplikat dan mengkredit escrow penjual tepat 1x.',
            ],
            [
                'id' => 3,
                'code' => 'TEST-03',
                'badge' => 'ANTI-TAMPERING 403',
                'title' => 'Spoofed Signature & Tampered Amount Attack',
                'summary' => 'Memalsukan gross_amount menjadi Rp 1 dan signature SHA-512 palsu untuk membuktikan penolakan HTTP 403 dan pencatatan forensik tanpa meracuni slot idempotency.',
            ],
            [
                'id' => 4,
                'code' => 'TEST-04',
                'badge' => 'ZOMBIE RECOVERY',
                'title' => 'Zombie Late Payment Recovery (EXPIRED -> PAID_LATE_REFUNDED)',
                'summary' => 'Menyapu invoice UNPAID menjadi EXPIRED + melepas reservasi brankas, lalu menerima webhook settlement telat dan mengamankan 100% dana ke Saldo Dompet Pembeli.',
            ],
            [
                'id' => 5,
                'code' => 'TEST-05',
                'badge' => 'HYBRID ROLLBACK',
                'title' => 'Hybrid Payment Expiry Rollback (Rp 25K Wallet + QRIS)',
                'summary' => 'Memotong Rp 25.000 via HYBRID_WALLET_HOLD, mempercepat expires_at, lalu menjalankan sweeper untuk membuktikan pengembalian otomatis HYBRID_EXPIRY_REFUND.',
            ],
            [
                'id' => 6,
                'code' => 'TEST-06',
                'badge' => 'BLIND-INDEX AES-256',
                'title' => 'AES-256 Blind-Index Duplicate Vault Guard',
                'summary' => 'Membuktikan random IV AES-256 menghasilkan ciphertext berbeda untuk kode sama, namun payload_blind_hash HMAC-SHA256 tetap memblokir duplikat 100% akurat.',
            ],
            [
                'id' => 7,
                'code' => 'TEST-07',
                'badge' => 'H2H AUTO-REVERSAL',
                'title' => 'Digiflazz H2H Async Failure Auto-Refund',
                'summary' => 'Menembakkan callback Digiflazz H2H berstatus gagal pada pesanan PROCESSING untuk membuktikan transisi REFUNDED, debit escrow penjual, kredit dompet pembeli, dan restorasi stok.',
            ],
            [
                'id' => 8,
                'code' => 'TEST-08',
                'badge' => 'TIME-TRAVEL WORKER',
                'title' => 'Time-Travel SLA Breach (-15m) & 24h Auto-Settlement (-25h)',
                'summary' => 'Memutar mundur timer sla_deadline_at (-15 menit) dan auto_complete_at (-25 jam), lalu mengeksekusi refund SLA + worker ESCROW_RELEASE double-entry.',
            ],
        ];

        return view('admin.chaos-lab', compact('scenarios', 'recentWebhooks', 'recentMutations', 'systemTelemetry'));
    }

    public function runScenario(int $scenario): JsonResponse
    {
        try {
            $result = $this->chaosLabService->runScenario($scenario);

            return response()->json([
                'ok' => true,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'exception' => class_basename($e),
            ], 422);
        }
    }
}
