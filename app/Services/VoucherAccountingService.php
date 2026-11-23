<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherUsage;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VoucherAccountingService
{
    /**
     * Preview voucher eligibility and exact Rupiah discount (with pro-rata allocation across orders).
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $cartBreakdown
     * @return array<string, mixed>
     */
    public function previewVoucherDiscount(string $code, int $buyerId, Collection|array $cartBreakdown): array
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            throw new DomainException('Masukkan kode voucher terlebih dahulu.');
        }

        $voucher = Voucher::where('code', $normalized)->first();
        if (! $voucher) {
            throw new DomainException("Kode voucher [{$normalized}] tidak ditemukan.");
        }

        $this->assertVoucherEligibilityBase($voucher, $buyerId);

        $orders = array_values($cartBreakdown instanceof Collection ? $cartBreakdown->all() : $cartBreakdown);
        $eligibleIndices = $this->findEligibleOrderIndices($voucher, $orders);

        if (empty($eligibleIndices)) {
            throw new DomainException("Voucher [{$voucher->code}] tidak berlaku untuk produk/toko di keranjang Anda.");
        }

        $eligibleSubtotal = 0.00;
        foreach ($eligibleIndices as $idx) {
            $eligibleSubtotal += (float) ($orders[$idx]['net_basis'] ?? $orders[$idx]['gross_amount'] ?? 0);
        }
        $eligibleSubtotal = round($eligibleSubtotal, 2);

        if ($eligibleSubtotal < (float) $voucher->min_purchase_amount) {
            throw new DomainException(
                "Minimal belanja untuk voucher [{$voucher->code}] adalah Rp ".number_format((float) $voucher->min_purchase_amount, 0, ',', '.').'.'
            );
        }

        $totalDiscount = $this->computeDiscountAmount($voucher, $eligibleSubtotal);
        $allocations = $this->allocateProRata($orders, $eligibleIndices, $totalDiscount);

        return [
            'valid' => true,
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'title' => $voucher->title,
            'issuer_type' => $voucher->issuer_type,
            'subsidy_bearer' => $voucher->issuer_type,
            'seller_id' => $voucher->seller_id ? (int) $voucher->seller_id : null,
            'eligible_subtotal' => $eligibleSubtotal,
            'discount_amount' => $totalDiscount,
            'allocations' => $allocations,
        ];
    }

    /**
     * Reserve vouchers atomically under ascending ID row locks inside CheckoutSplitOrderService transaction.
     *
     * @param  list<array<string, mixed>>  $preparedOrders
     * @param  array<int, string>  $sellerVoucherCodesBySellerId
     * @return array{
     *   orders: list<array<string, mixed>>,
     *   seller_discount_total: float,
     *   platform_discount_total: float,
     *   usages_to_create: list<array<string, mixed>>
     * }
     */
    public function reserveVouchersForCheckout(
        int $buyerId,
        array $preparedOrders,
        ?string $platformVoucherCode = null,
        array $sellerVoucherCodesBySellerId = [],
    ): array {
        // Initialize default Phase 3 discount fields on every prepared order
        foreach ($preparedOrders as &$ord) {
            $ord['seller_discount_amount'] = 0.00;
            $ord['platform_discount_amount'] = 0.00;
            $ord['net_basis'] = (float) $ord['gross_amount'];
            $ord['buyer_payable_amount'] = (float) $ord['gross_amount'];
        }
        unset($ord);

        $cleanPlatformCode = $platformVoucherCode !== null ? strtoupper(trim($platformVoucherCode)) : '';
        $cleanSellerCodes = [];
        foreach ($sellerVoucherCodesBySellerId as $sid => $vCode) {
            $upper = strtoupper(trim((string) $vCode));
            if ($upper !== '') {
                $cleanSellerCodes[(int) $sid] = $upper;
            }
        }

        $allRequestedCodes = array_values(array_unique(array_filter(array_merge(
            $cleanPlatformCode !== '' ? [$cleanPlatformCode] : [],
            array_values($cleanSellerCodes),
        ))));

        if (empty($allRequestedCodes)) {
            return [
                'orders' => $preparedOrders,
                'seller_discount_total' => 0.00,
                'platform_discount_total' => 0.00,
                'usages_to_create' => [],
            ];
        }

        // DEADLOCK PREVENTION: Lock all requested voucher rows in ascending ID order!
        $voucherIds = DB::table('vouchers')
            ->whereIn('code', $allRequestedCodes)
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->all();

        $lockedVouchers = Voucher::whereIn('id', $voucherIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('code');

        $usagesToCreate = [];
        $sellerDiscountTotal = 0.00;
        $platformDiscountTotal = 0.00;

        // 1. Process Seller Vouchers FIRST (reduces seller net_basis, commission & seller_escrow_amount)
        foreach ($cleanSellerCodes as $targetSellerId => $sellerCode) {
            /** @var Voucher|null $voucher */
            $voucher = $lockedVouchers->get($sellerCode);
            if (! $voucher) {
                throw new DomainException("Kode voucher toko [{$sellerCode}] tidak ditemukan.");
            }
            if ($voucher->issuer_type !== 'seller' || (int) $voucher->seller_id !== $targetSellerId) {
                throw new DomainException("Voucher [{$sellerCode}] bukan voucher milik toko tersebut.");
            }

            $this->assertVoucherEligibilityBase($voucher, $buyerId);

            $eligibleIndices = $this->findEligibleOrderIndices($voucher, $preparedOrders);
            if (empty($eligibleIndices)) {
                throw new DomainException("Voucher toko [{$voucher->code}] tidak cocok dengan item di keranjang.");
            }

            $eligibleSubtotal = 0.00;
            foreach ($eligibleIndices as $idx) {
                $eligibleSubtotal += (float) $preparedOrders[$idx]['gross_amount'];
            }
            $eligibleSubtotal = round($eligibleSubtotal, 2);

            if ($eligibleSubtotal < (float) $voucher->min_purchase_amount) {
                throw new DomainException(
                    "Minimal belanja voucher toko [{$voucher->code}] adalah Rp ".number_format((float) $voucher->min_purchase_amount, 0, ',', '.').'.'
                );
            }

            $discountAmount = $this->computeDiscountAmount($voucher, $eligibleSubtotal);
            $allocations = $this->allocateProRata($preparedOrders, $eligibleIndices, $discountAmount);

            foreach ($allocations as $idx => $alloc) {
                $preparedOrders[$idx]['seller_discount_amount'] = round($preparedOrders[$idx]['seller_discount_amount'] + $alloc, 2);
                $netBasis = round(max(0.0, (float) $preparedOrders[$idx]['gross_amount'] - (float) $preparedOrders[$idx]['seller_discount_amount']), 2);
                $preparedOrders[$idx]['net_basis'] = $netBasis;

                // Recompute commission & seller_escrow_amount based on net_basis (gross - seller_discount)
                $recomputedCommission = $this->recomputeOrderCommission($preparedOrders[$idx], $netBasis);
                $preparedOrders[$idx]['platform_commission'] = $recomputedCommission;
                $preparedOrders[$idx]['seller_escrow_amount'] = round(max(0.0, $netBasis - $recomputedCommission), 2);
            }

            DB::table('vouchers')->where('id', $voucher->id)->increment('quota_used', 1);
            $sellerDiscountTotal = round($sellerDiscountTotal + $discountAmount, 2);

            $usagesToCreate[] = [
                'voucher_id' => $voucher->id,
                'order_index' => count($eligibleIndices) === 1 ? $eligibleIndices[0] : null,
                'subsidy_bearer' => 'seller',
                'discount_amount' => $discountAmount,
            ];
        }

        // 2. Process Platform Voucher SECOND (pro-rata across eligible orders, NEVER penalizing seller_escrow_amount!)
        if ($cleanPlatformCode !== '') {
            /** @var Voucher|null $platformVoucher */
            $platformVoucher = $lockedVouchers->get($cleanPlatformCode);
            if (! $platformVoucher) {
                throw new DomainException("Kode voucher platform [{$cleanPlatformCode}] tidak ditemukan.");
            }
            if ($platformVoucher->issuer_type !== 'platform') {
                throw new DomainException("Kode [{$cleanPlatformCode}] adalah voucher toko, gunakan pada kolom Voucher Toko.");
            }

            $this->assertVoucherEligibilityBase($platformVoucher, $buyerId);

            $eligibleIndices = $this->findEligibleOrderIndices($platformVoucher, $preparedOrders);
            if (empty($eligibleIndices)) {
                throw new DomainException("Voucher platform [{$platformVoucher->code}] tidak berlaku untuk produk di keranjang Anda.");
            }

            $eligibleSubtotal = 0.00;
            foreach ($eligibleIndices as $idx) {
                $eligibleSubtotal += (float) $preparedOrders[$idx]['net_basis'];
            }
            $eligibleSubtotal = round($eligibleSubtotal, 2);

            if ($eligibleSubtotal < (float) $platformVoucher->min_purchase_amount) {
                throw new DomainException(
                    "Minimal belanja voucher [{$platformVoucher->code}] adalah Rp ".number_format((float) $platformVoucher->min_purchase_amount, 0, ',', '.').'.'
                );
            }

            $discountAmount = $this->computeDiscountAmount($platformVoucher, $eligibleSubtotal);
            $allocations = $this->allocateProRata($preparedOrders, $eligibleIndices, $discountAmount);

            foreach ($allocations as $idx => $alloc) {
                $preparedOrders[$idx]['platform_discount_amount'] = round($preparedOrders[$idx]['platform_discount_amount'] + $alloc, 2);
            }

            DB::table('vouchers')->where('id', $platformVoucher->id)->increment('quota_used', 1);
            $platformDiscountTotal = round($platformDiscountTotal + $discountAmount, 2);

            $usagesToCreate[] = [
                'voucher_id' => $platformVoucher->id,
                'order_index' => count($eligibleIndices) === 1 ? $eligibleIndices[0] : null,
                'subsidy_bearer' => 'platform',
                'discount_amount' => $discountAmount,
            ];
        }

        // Compute final buyer_payable_amount on every child order
        foreach ($preparedOrders as &$ord) {
            $ord['buyer_payable_amount'] = round(
                max(0.0, (float) $ord['gross_amount'] - (float) $ord['seller_discount_amount'] - (float) $ord['platform_discount_amount']),
                2,
            );
        }
        unset($ord);

        return [
            'orders' => $preparedOrders,
            'seller_discount_total' => $sellerDiscountTotal,
            'platform_discount_total' => $platformDiscountTotal,
            'usages_to_create' => $usagesToCreate,
        ];
    }

    /**
     * Persist voucher_usages records after invoice & child orders have been inserted.
     *
     * @param  list<array<string, mixed>>  $usagesToCreate
     * @param  array<int, int>  $orderIdByIndex
     */
    public function persistReservedUsages(int $buyerId, int $invoiceId, array $usagesToCreate, array $orderIdByIndex, string $initialStatus = 'reserved'): void
    {
        foreach ($usagesToCreate as $usage) {
            $orderIdx = $usage['order_index'];
            VoucherUsage::create([
                'voucher_id' => (int) $usage['voucher_id'],
                'user_id' => $buyerId,
                'payment_invoice_id' => $invoiceId,
                'order_id' => $orderIdx !== null && isset($orderIdByIndex[$orderIdx]) ? $orderIdByIndex[$orderIdx] : null,
                'subsidy_bearer' => (string) $usage['subsidy_bearer'],
                'discount_amount' => number_format((float) $usage['discount_amount'], 2, '.', ''),
                'status' => $initialStatus,
            ]);
        }
    }

    public function markVouchersAppliedForInvoice(int $invoiceId): void
    {
        VoucherUsage::where('payment_invoice_id', $invoiceId)
            ->where('status', 'reserved')
            ->update([
                'status' => 'applied',
                'updated_at' => now(),
            ]);
    }

    public function releaseVouchersForExpiredInvoice(int $invoiceId): int
    {
        $reservedUsages = VoucherUsage::where('payment_invoice_id', $invoiceId)
            ->where('status', 'reserved')
            ->orderBy('voucher_id', 'asc')
            ->lockForUpdate()
            ->get();

        if ($reservedUsages->isEmpty()) {
            return 0;
        }

        $voucherIds = $reservedUsages->pluck('voucher_id')->unique()->sort()->values()->all();
        DB::table('vouchers')
            ->whereIn('id', $voucherIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($reservedUsages as $usage) {
            DB::table('vouchers')
                ->where('id', $usage->voucher_id)
                ->where('quota_used', '>', 0)
                ->decrement('quota_used', 1);

            $usage->status = 'released';
            $usage->save();
        }

        return $reservedUsages->count();
    }

    private function assertVoucherEligibilityBase(Voucher $voucher, int $buyerId): void
    {
        $now = now();

        if (! $voucher->is_active) {
            throw new DomainException("Voucher [{$voucher->code}] sedang tidak aktif.");
        }

        if ($now->lt($voucher->starts_at) || $now->gt($voucher->ends_at)) {
            throw new DomainException("Voucher [{$voucher->code}] sudah kedaluwarsa atau belum dimulai.");
        }

        if ((int) $voucher->quota_used >= (int) $voucher->quota_total) {
            throw new DomainException("Kuota voucher [{$voucher->code}] sudah habis.");
        }

        $userUsedCount = VoucherUsage::where('voucher_id', $voucher->id)
            ->where('user_id', $buyerId)
            ->whereIn('status', ['reserved', 'applied'])
            ->count();

        if ($userUsedCount >= (int) $voucher->max_uses_per_user) {
            throw new DomainException("Anda telah mencapai batas pemakaian untuk voucher [{$voucher->code}].");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<int>
     */
    private function findEligibleOrderIndices(Voucher $voucher, array $orders): array
    {
        $indices = [];
        foreach ($orders as $idx => $order) {
            if ($voucher->issuer_type === 'seller' && (int) ($order['seller_id'] ?? 0) !== (int) $voucher->seller_id) {
                continue;
            }

            if ($voucher->game_id !== null) {
                $hasMatchingGame = false;
                foreach ($order['items'] ?? [] as $item) {
                    $itemGameId = (int) ($item['game_id'] ?? $item['product']?->gameCategory->game_id ?? 0);
                    if ($itemGameId === (int) $voucher->game_id) {
                        $hasMatchingGame = true;
                        break;
                    }
                }
                if (! $hasMatchingGame) {
                    continue;
                }
            }

            $basis = (float) ($order['net_basis'] ?? $order['gross_amount'] ?? 0);
            if ($basis > 0) {
                $indices[] = $idx;
            }
        }

        return $indices;
    }

    private function computeDiscountAmount(Voucher $voucher, float $eligibleSubtotal): float
    {
        if ($voucher->discount_type === 'fixed') {
            return round(min((float) $voucher->discount_value, $eligibleSubtotal), 2);
        }

        $rawPercent = floor($eligibleSubtotal * ((float) $voucher->discount_value / 100));
        if ($voucher->max_discount_amount !== null && (float) $voucher->max_discount_amount > 0) {
            $rawPercent = min($rawPercent, (float) $voucher->max_discount_amount);
        }

        return round(min($rawPercent, $eligibleSubtotal), 2);
    }

    /**
     * Pro-rata integer Rupiah allocation placing any rounding remainder onto the last eligible order.
     *
     * @param  list<array<string, mixed>>  $orders
     * @param  list<int>  $eligibleIndices
     * @return array<int, float>
     */
    private function allocateProRata(array $orders, array $eligibleIndices, float $totalDiscount): array
    {
        $totalEligibleBasis = 0.0;
        foreach ($eligibleIndices as $idx) {
            $totalEligibleBasis += (float) ($orders[$idx]['net_basis'] ?? $orders[$idx]['gross_amount'] ?? 0);
        }

        if ($totalEligibleBasis <= 0 || $totalDiscount <= 0) {
            return [];
        }

        $allocations = [];
        $allocatedSum = 0.0;
        $lastPos = count($eligibleIndices) - 1;

        foreach ($eligibleIndices as $pos => $idx) {
            $orderBasis = (float) ($orders[$idx]['net_basis'] ?? $orders[$idx]['gross_amount'] ?? 0);
            if ($pos === $lastPos) {
                $share = round(max(0.0, min($orderBasis, $totalDiscount - $allocatedSum)), 2);
            } else {
                $share = round(min($orderBasis, floor($totalDiscount * ($orderBasis / $totalEligibleBasis))), 2);
                $allocatedSum = round($allocatedSum + $share, 2);
            }
            $allocations[$idx] = $share;
        }

        return $allocations;
    }

    /**
     * Recompute platform commission on net_basis (gross - seller_discount) preserving exact fee rates.
     *
     * @param  array<string, mixed>  $orderData
     */
    private function recomputeOrderCommission(array $orderData, float $netBasis): float
    {
        $gross = (float) ($orderData['gross_amount'] ?? 0);
        if ($gross <= 0 || $netBasis <= 0) {
            return 0.00;
        }

        $items = $orderData['items'] ?? [];
        if (count($items) === 1 && isset($items[0]['fee_percent'])) {
            return round(floor($netBasis * ((float) $items[0]['fee_percent'] / 100)), 2);
        }

        $weightedCommission = 0.0;
        $ratio = $netBasis / $gross;
        foreach ($items as $item) {
            $lineSubtotal = round((float) $item['unit_price'] * (int) $item['quantity'], 2);
            $effectiveLine = $lineSubtotal * $ratio;
            $weightedCommission += floor($effectiveLine * ((float) $item['fee_percent'] / 100));
        }

        return round($weightedCommission, 2);
    }
}
