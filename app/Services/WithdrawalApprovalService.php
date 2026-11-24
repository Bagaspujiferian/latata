<?php

namespace App\Services;

use App\Enums\WithdrawalApprovalStage;
use App\Models\User;
use App\Models\WithdrawalApproval;
use App\Models\WithdrawalRequest;
use App\Security\AdminPermissionMatrix;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class WithdrawalApprovalService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly AdminCmsAndAuditService $auditService,
        private readonly NotificationService $notificationService,
    ) {}

    public function getMakerCheckerThreshold(): float
    {
        return (float) config('nexatrade.withdrawal_maker_checker_threshold', 5000000);
    }

    /**
     * Approve a withdrawal request with Single-Step (< threshold) or Dual-Approval Maker-Checker (>= threshold).
     */
    public function approve(int $withdrawalId, int $adminId, ?string $notes = null): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawalId, $adminId, $notes): WithdrawalRequest {
            $admin = User::findOrFail($adminId);
            $this->assertAdminCanApproveWithdrawals($admin);

            $withdrawal = WithdrawalRequest::where('id', $withdrawalId)
                ->lockForUpdate()
                ->first();

            if (! $withdrawal) {
                throw new DomainException("Permintaan penarikan #{$withdrawalId} tidak ditemukan.");
            }

            if ((int) $withdrawal->user_id === (int) $admin->id) {
                throw new AuthorizationException('Conflict of Interest: Admin dilarang menyetujui penarikan saldo miliknya sendiri.');
            }

            $amount = round((float) $withdrawal->amount, 2);
            $threshold = $this->getMakerCheckerThreshold();
            $requiresDualApproval = $amount >= $threshold;
            $now = now();

            if ($withdrawal->status === 'PENDING') {
                if (! $requiresDualApproval) {
                    WithdrawalApproval::create([
                        'withdrawal_id' => $withdrawal->id,
                        'admin_id' => $admin->id,
                        'stage' => WithdrawalApprovalStage::MAKER,
                        'decision' => 'approved',
                        'notes' => $notes ?? 'Persetujuan tunggal di bawah ambang Maker-Checker.',
                        'decided_at' => $now,
                        'created_at' => $now,
                    ]);

                    $withdrawal->update([
                        'status' => 'APPROVED_PAID',
                        'processed_by' => $admin->id,
                        'processed_at' => $now,
                    ]);

                    $this->auditService->recordAudit(
                        adminId: (int) $admin->id,
                        action: 'WITHDRAWAL_APPROVED_SINGLE',
                        entityType: 'withdrawal_request',
                        entityId: (int) $withdrawal->id,
                        before: ['status' => 'PENDING'],
                        after: ['status' => 'APPROVED_PAID', 'stage' => 'maker', 'amount' => $amount],
                        notes: "Penarikan {$withdrawal->withdraw_code} disetujui (Single-Stage < Rp ".number_format($threshold, 0, ',', '.').').',
                    );

                    $this->dispatchApprovedNotificationAfterCommit($withdrawal);

                    return $withdrawal->refresh()->load(['approvals.admin', 'processor']);
                }

                // Dual-Approval Stage 1 (Maker) -> transitions PENDING to MAKER_APPROVED
                WithdrawalApproval::create([
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id' => $admin->id,
                    'stage' => WithdrawalApprovalStage::MAKER,
                    'decision' => 'approved',
                    'notes' => $notes ?? 'Persetujuan Tahap 1 (Maker). Menunggu verifikasi Tahap 2 (Checker).',
                    'decided_at' => $now,
                    'created_at' => $now,
                ]);

                $withdrawal->update([
                    'status' => 'MAKER_APPROVED',
                    'processed_by' => $admin->id,
                ]);

                $this->auditService->recordAudit(
                    adminId: (int) $admin->id,
                    action: 'WITHDRAWAL_MAKER_APPROVED',
                    entityType: 'withdrawal_request',
                    entityId: (int) $withdrawal->id,
                    before: ['status' => 'PENDING'],
                    after: ['status' => 'MAKER_APPROVED', 'maker_admin_id' => $admin->id, 'amount' => $amount],
                    notes: "Penarikan {$withdrawal->withdraw_code} disetujui Tahap 1 (Maker) oleh {$admin->name}.",
                );

                return $withdrawal->refresh()->load(['approvals.admin', 'processor']);
            }

            if ($withdrawal->status === 'MAKER_APPROVED') {
                $makerApproval = WithdrawalApproval::where('withdrawal_id', $withdrawal->id)
                    ->where('stage', WithdrawalApprovalStage::MAKER->value)
                    ->lockForUpdate()
                    ->first();

                $makerAdminId = $makerApproval ? (int) $makerApproval->admin_id : (int) $withdrawal->processed_by;

                if ($makerAdminId === (int) $admin->id) {
                    throw new AuthorizationException(
                        'Pelanggaran Maker-Checker (Four-Eyes Principle): Admin Checker (Tahap 2) wajib berbeda dengan Admin Maker (Tahap 1).'
                    );
                }

                WithdrawalApproval::create([
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id' => $admin->id,
                    'stage' => WithdrawalApprovalStage::CHECKER,
                    'decision' => 'approved',
                    'notes' => $notes ?? 'Persetujuan Final Tahap 2 (Checker).',
                    'decided_at' => $now,
                    'created_at' => $now,
                ]);

                $withdrawal->update([
                    'status' => 'APPROVED_PAID',
                    'processed_by' => $admin->id,
                    'processed_at' => $now,
                ]);

                $this->auditService->recordAudit(
                    adminId: (int) $admin->id,
                    action: 'WITHDRAWAL_CHECKER_APPROVED',
                    entityType: 'withdrawal_request',
                    entityId: (int) $withdrawal->id,
                    before: ['status' => 'MAKER_APPROVED', 'maker_admin_id' => $makerAdminId],
                    after: ['status' => 'APPROVED_PAID', 'checker_admin_id' => $admin->id, 'amount' => $amount],
                    notes: "Penarikan {$withdrawal->withdraw_code} disetujui Final Tahap 2 (Checker) oleh {$admin->name}.",
                );

                $this->dispatchApprovedNotificationAfterCommit($withdrawal);

                return $withdrawal->refresh()->load(['approvals.admin', 'processor']);
            }

            throw new DomainException(
                "Penarikan {$withdrawal->withdraw_code} sudah diproses sebelumnya (status: {$withdrawal->status})."
            );
        });
    }

    /**
     * Reject a withdrawal from PENDING or MAKER_APPROVED and atomically refund held funds to available_balance.
     */
    public function reject(int $withdrawalId, int $adminId, string $reason): WithdrawalRequest
    {
        $cleanReason = mb_substr(trim($reason), 0, 255);
        if ($cleanReason === '') {
            throw new DomainException('Alasan penolakan penarikan dana wajib diisi.');
        }

        return DB::transaction(function () use ($withdrawalId, $adminId, $cleanReason): WithdrawalRequest {
            $admin = User::findOrFail($adminId);
            $this->assertAdminCanApproveWithdrawals($admin);

            $withdrawal = WithdrawalRequest::where('id', $withdrawalId)
                ->lockForUpdate()
                ->first();

            if (! $withdrawal) {
                throw new DomainException("Permintaan penarikan #{$withdrawalId} tidak ditemukan.");
            }

            if ((int) $withdrawal->user_id === (int) $admin->id) {
                throw new AuthorizationException('Conflict of Interest: Admin dilarang memproses penarikan saldo miliknya sendiri.');
            }

            if (! in_array($withdrawal->status, ['PENDING', 'MAKER_APPROVED'], true)) {
                throw new DomainException(
                    "Penarikan {$withdrawal->withdraw_code} sudah diproses sebelumnya (status: {$withdrawal->status})."
                );
            }

            $previousStatus = (string) $withdrawal->status;
            $now = now();

            $existingMaker = WithdrawalApproval::where('withdrawal_id', $withdrawal->id)
                ->where('stage', WithdrawalApprovalStage::MAKER->value)
                ->lockForUpdate()
                ->first();

            if (! $existingMaker) {
                WithdrawalApproval::create([
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id' => $admin->id,
                    'stage' => WithdrawalApprovalStage::MAKER,
                    'decision' => 'rejected',
                    'notes' => $cleanReason,
                    'decided_at' => $now,
                    'created_at' => $now,
                ]);
            } elseif ((int) $existingMaker->admin_id !== (int) $admin->id) {
                WithdrawalApproval::create([
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id' => $admin->id,
                    'stage' => WithdrawalApprovalStage::CHECKER,
                    'decision' => 'rejected',
                    'notes' => $cleanReason,
                    'decided_at' => $now,
                    'created_at' => $now,
                ]);
            }

            $withdrawal->update([
                'status' => 'REJECTED_REFUNDED',
                'processed_by' => $admin->id,
                'rejection_reason' => $cleanReason,
                'processed_at' => $now,
            ]);

            $this->ledgerService->refundRejectedWithdrawal(
                userId: (int) $withdrawal->user_id,
                amount: (float) $withdrawal->amount,
                withdrawCode: $withdrawal->withdraw_code,
            );

            $this->auditService->recordAudit(
                adminId: (int) $admin->id,
                action: 'WITHDRAWAL_REJECTED',
                entityType: 'withdrawal_request',
                entityId: (int) $withdrawal->id,
                before: ['status' => $previousStatus],
                after: ['status' => 'REJECTED_REFUNDED', 'reason' => $cleanReason],
                notes: "Penarikan {$withdrawal->withdraw_code} ditolak dari status [{$previousStatus}] & dana dikembalikan: {$cleanReason}",
            );

            $userId = (int) $withdrawal->user_id;
            $wdCode = (string) $withdrawal->withdraw_code;
            $wdAmount = (float) $withdrawal->amount;
            $wdId = (int) $withdrawal->id;

            DB::afterCommit(function () use ($userId, $wdCode, $cleanReason, $wdAmount, $wdId): void {
                $this->notificationService->notify(
                    userId: $userId,
                    category: 'withdrawal',
                    title: "Penarikan {$wdCode} Ditolak & Dikembalikan",
                    body: "Alasan: {$cleanReason}. Saldo Rp ".number_format($wdAmount, 0, ',', '.').' dikembalikan ke Saldo Aktif.',
                    actionUrl: '/seller/wallet',
                    refType: 'withdrawal',
                    refId: $wdId,
                );
            });

            return $withdrawal->refresh()->load(['approvals.admin', 'processor']);
        });
    }

    private function assertAdminCanApproveWithdrawals(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new DomainException('Hanya Admin yang dapat memproses penarikan saldo.');
        }

        if (! AdminPermissionMatrix::allows($admin, 'withdrawals.approve')) {
            throw new AuthorizationException(
                'Akses Ditolak (RBAC): Subrole Admin Anda tidak memiliki izin [withdrawals.approve].'
            );
        }
    }

    private function dispatchApprovedNotificationAfterCommit(WithdrawalRequest $withdrawal): void
    {
        $userId = (int) $withdrawal->user_id;
        $wdCode = (string) $withdrawal->withdraw_code;
        $netPayout = (float) $withdrawal->net_payout;
        $bankName = (string) $withdrawal->bank_name;
        $accountNumber = (string) $withdrawal->account_number;
        $wdId = (int) $withdrawal->id;

        DB::afterCommit(function () use ($userId, $wdCode, $netPayout, $bankName, $accountNumber, $wdId): void {
            $this->notificationService->notify(
                userId: $userId,
                category: 'withdrawal',
                title: "Penarikan {$wdCode} Disetujui",
                body: 'Dana bersih Rp '.number_format($netPayout, 0, ',', '.')." telah ditransfer ke {$bankName} ({$accountNumber}).",
                actionUrl: '/seller/wallet',
                refType: 'withdrawal',
                refId: $wdId,
            );
        });
    }
}
