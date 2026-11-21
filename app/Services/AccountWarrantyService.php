<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderWarranty;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WarrantyClaim;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountWarrantyService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly NotificationService $notificationService,
        private readonly AdminCmsAndAuditService $auditService,
    ) {}

    /**
     * Automatically issue an Anti-Hackback Warranty certificate when eligible orders reach DELIVERED/COMPLETED.
     */
    public function issueWarrantyIfEligible(Order $order): ?OrderWarranty
    {
        $order->loadMissing(['items.product.gameCategory']);

        $warrantyDays = 0;
        foreach ($order->items as $item) {
            $catDays = (int) ($item->product?->gameCategory?->warranty_days ?? 0);
            if ($catDays > $warrantyDays) {
                $warrantyDays = $catDays;
            }
        }

        if ($warrantyDays <= 0) {
            return null;
        }

        $existing = OrderWarranty::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $coverage = $order->refundableBuyerAmount();
        $startsAt = now();
        $expiresAt = $startsAt->copy()->addDays($warrantyDays);

        $warranty = OrderWarranty::create([
            'order_id' => $order->id,
            'buyer_id' => $order->buyer_id,
            'seller_id' => $order->seller_id,
            'warranty_days' => $warrantyDays,
            'coverage_amount' => number_format($coverage, 2, '.', ''),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        $this->notificationService->notify(
            userId: (int) $order->buyer_id,
            category: 'warranty',
            title: "Sertifikat Garansi Anti-Hackback {$warrantyDays} Hari Aktif",
            body: "Pesanan {$order->order_code} dilindungi garansi hingga ".$expiresAt->format('d M Y').' (Coverage Rp '.number_format($coverage, 0, ',', '.').').',
            actionUrl: "/buyer/orders/{$order->order_code}",
            refType: 'order_warranty',
            refId: (int) $warranty->id,
        );

        return $warranty;
    }

    /**
     * @param  array{reason_category: string, description: string}  $data
     * @param  list<UploadedFile>  $evidenceFiles
     */
    public function fileWarrantyClaim(int $orderId, int $buyerId, array $data, array $evidenceFiles = []): WarrantyClaim
    {
        return DB::transaction(function () use ($orderId, $buyerId, $data, $evidenceFiles): WarrantyClaim {
            $warranty = OrderWarranty::with('order')
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $warranty) {
                throw new DomainException('Pesanan ini tidak memiliki sertifikat garansi Anti-Hackback.');
            }

            if ((int) $warranty->buyer_id !== $buyerId) {
                throw new DomainException('Hanya pembeli pemilik pesanan yang dapat mengajukan klaim garansi.');
            }

            if ($warranty->status !== 'active') {
                throw new DomainException("Sertifikat garansi tidak dalam status aktif (status saat ini: {$warranty->status}).");
            }

            if (now()->greaterThan($warranty->expires_at)) {
                $warranty->status = 'expired';
                $warranty->save();
                throw new DomainException('Masa berlaku garansi Anti-Hackback untuk pesanan ini telah habis.');
            }

            $storedEvidencePaths = [];
            foreach (array_slice($evidenceFiles, 0, 4) as $file) {
                // Store evidence STRICTLY on private local disk (NEVER public!)
                $path = Storage::disk('local')->putFile("private/warranty-claims/{$orderId}", $file);
                if (is_string($path)) {
                    $storedEvidencePaths[] = $path;
                }
            }

            $warranty->status = 'claimed';
            $warranty->save();

            $claimCode = 'WCL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $claim = WarrantyClaim::create([
                'claim_code' => $claimCode,
                'order_warranty_id' => $warranty->id,
                'order_id' => $warranty->order_id,
                'buyer_id' => $warranty->buyer_id,
                'seller_id' => $warranty->seller_id,
                'reason_category' => (string) $data['reason_category'],
                'description' => trim((string) $data['description']),
                'evidence_paths' => ! empty($storedEvidencePaths) ? $storedEvidencePaths : null,
                'status' => 'open',
            ]);

            $this->notificationService->notify(
                userId: (int) $warranty->seller_id,
                category: 'warranty',
                title: "Klaim Garansi Hackback Masuk: {$claimCode}",
                body: "Pembeli mengajukan klaim Anti-Hackback untuk pesanan {$warranty->order->order_code}. Tim Risiko Admin sedang meninjau.",
                actionUrl: '/seller/wallet',
                refType: 'warranty_claim',
                refId: (int) $claim->id,
            );

            $this->notificationService->notifyAdmins(
                category: 'warranty',
                title: "Klaim Garansi Baru: {$claimCode}",
                body: "Klaim Anti-Hackback senilai Rp ".number_format((float) $warranty->coverage_amount, 0, ',', '.')." untuk {$warranty->order->order_code} menunggu putusan.",
                actionUrl: '/admin/warranties',
                refType: 'warranty_claim',
                refId: (int) $claim->id,
            );

            return $claim->fresh(['warranty', 'order', 'buyer', 'sellerProfile']);
        });
    }

    public function resolveWarrantyClaim(int $claimId, int $adminId, string $decision, string $adminNotes): WarrantyClaim
    {
        if (! in_array($decision, ['APPROVE_REFUND', 'REJECT'], true)) {
            throw new DomainException("Keputusan klaim garansi tidak valid: [{$decision}].");
        }

        $trimmedNotes = trim($adminNotes);
        if ($trimmedNotes === '') {
            throw new DomainException('Catatan putusan Admin wajib diisi.');
        }

        return DB::transaction(function () use ($claimId, $adminId, $decision, $trimmedNotes): WarrantyClaim {
            $admin = User::findOrFail($adminId);
            if (! $admin->isAdmin()) {
                throw new DomainException('Hanya Admin yang dapat memutus klaim garansi.');
            }

            $claim = WarrantyClaim::where('id', $claimId)->lockForUpdate()->first();
            if (! $claim) {
                throw new DomainException("Klaim garansi #{$claimId} tidak ditemukan.");
            }

            if (! in_array($claim->status, ['open', 'investigating'], true)) {
                throw new DomainException("Klaim garansi {$claim->claim_code} sudah diputus sebelumnya (status: {$claim->status}).");
            }

            $warranty = OrderWarranty::where('id', $claim->order_warranty_id)->lockForUpdate()->firstOrFail();
            $beforeState = [
                'claim_status' => $claim->status,
                'warranty_status' => $warranty->status,
            ];

            if ($decision === 'REJECT') {
                $claim->status = 'rejected';
                $claim->admin_resolution_notes = $trimmedNotes;
                $claim->resolved_by = $adminId;
                $claim->resolved_at = now();
                $claim->save();

                $warranty->status = now()->lessThanOrEqualTo($warranty->expires_at) ? 'active' : 'expired';
                $warranty->save();

                $this->auditService->recordAudit(
                    adminId: $adminId,
                    action: 'WARRANTY_CLAIM_REJECTED',
                    entityType: 'warranty_claim',
                    entityId: (int) $claim->id,
                    before: $beforeState,
                    after: ['claim_status' => 'rejected', 'warranty_status' => $warranty->status],
                    notes: $trimmedNotes,
                );

                $this->notificationService->notify(
                    userId: (int) $claim->buyer_id,
                    category: 'warranty',
                    title: "Klaim Garansi {$claim->claim_code} Ditolak",
                    body: "Catatan Admin: {$trimmedNotes}",
                    actionUrl: '/buyer/warranties',
                    refType: 'warranty_claim',
                    refId: (int) $claim->id,
                );

                return $claim->fresh(['warranty', 'order', 'buyer', 'sellerProfile', 'resolver']);
            }

            // APPROVE_REFUND: Non-negative clawback waterfall (security_deposit -> available -> clawback_debt + suspend) + 100% buyer refund
            $coverage = (float) $warranty->coverage_amount;
            $clawback = $this->ledgerService->executeWarrantyClawbackAndRefund(
                sellerId: (int) $claim->seller_id,
                buyerId: (int) $claim->buyer_id,
                orderId: (int) $claim->order_id,
                coverageAmount: $coverage,
                claimCode: $claim->claim_code,
            );

            $sellerSuspended = false;
            if ($clawback['recorded_debt'] > 0) {
                $sellerProfile = SellerProfile::where('user_id', $claim->seller_id)->lockForUpdate()->first();
                if ($sellerProfile) {
                    $sellerProfile->is_suspended = true;
                    $sellerProfile->is_store_open = false;
                    $sellerProfile->suspension_reason = "Tunggakan klaim garansi hackback #{$claim->claim_code}";
                    $sellerProfile->save();
                    $sellerSuspended = true;
                }
            }

            $claim->status = 'approved_refund';
            $claim->clawback_from_deposit = number_format($clawback['from_deposit'], 2, '.', '');
            $claim->clawback_from_available = number_format($clawback['from_available'], 2, '.', '');
            $claim->recorded_seller_debt = number_format($clawback['recorded_debt'], 2, '.', '');
            $claim->admin_resolution_notes = $trimmedNotes;
            $claim->resolved_by = $adminId;
            $claim->resolved_at = now();
            $claim->save();

            $warranty->status = 'refunded';
            $warranty->save();

            $this->auditService->recordAudit(
                adminId: $adminId,
                action: 'WARRANTY_CLAIM_APPROVED_CLAWBACK',
                entityType: 'warranty_claim',
                entityId: (int) $claim->id,
                before: $beforeState,
                after: [
                    'claim_status' => 'approved_refund',
                    'warranty_status' => 'refunded',
                    'clawback_from_deposit' => $clawback['from_deposit'],
                    'clawback_from_available' => $clawback['from_available'],
                    'recorded_seller_debt' => $clawback['recorded_debt'],
                    'seller_auto_suspended' => $sellerSuspended,
                ],
                notes: $trimmedNotes,
            );

            $this->notificationService->notify(
                userId: (int) $claim->buyer_id,
                category: 'warranty',
                title: "Klaim Garansi {$claim->claim_code} Disetujui! Dana Cair 100%",
                body: 'Ganti rugi Rp '.number_format($coverage, 0, ',', '.').' telah dikreditkan ke Saldo Aktif Anda melalui Dana Jaminan Platform.',
                actionUrl: '/buyer/wallet',
                refType: 'warranty_claim',
                refId: (int) $claim->id,
            );

            $this->notificationService->notify(
                userId: (int) $claim->seller_id,
                category: 'warranty',
                title: "Clawback Garansi Hackback: {$claim->claim_code}",
                body: $sellerSuspended
                    ? 'Toko dibekukan otomatis karena kekurangan saldo clawback sebesar Rp '.number_format($clawback['recorded_debt'], 0, ',', '.').'.'
                    : 'Deposit/saldo dipotong Rp '.number_format($coverage, 0, ',', '.').' untuk penyelesaian klaim garansi pembeli.',
                actionUrl: '/seller/wallet',
                refType: 'warranty_claim',
                refId: (int) $claim->id,
            );

            return $claim->fresh(['warranty', 'order', 'buyer', 'sellerProfile', 'resolver']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function transferAvailableToSecurityDeposit(int $sellerId, float $amount): array
    {
        if ($amount < 10000) {
            throw new DomainException('Minimal penguncian Deposit Jaminan Anti-Hackback adalah Rp 10.000.');
        }

        $suffix = now()->format('YmdHis').'-'.strtoupper(Str::random(5));

        return $this->ledgerService->lockSecurityDeposit($sellerId, $amount, $suffix);
    }
}
