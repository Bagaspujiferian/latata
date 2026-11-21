<?php

namespace App\Services;

use App\Models\AdCreditPackage;
use App\Models\AdCreditPurchase;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBoost;
use App\Models\SellerProfile;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdCreditAndStoreLevelService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly NotificationService $notificationService,
    ) {}

    public function purchasePackageWithWallet(int $sellerId, int $packageId): AdCreditPurchase
    {
        return DB::transaction(function () use ($sellerId, $packageId): AdCreditPurchase {
            $package = AdCreditPackage::where('id', $packageId)
                ->where('is_active', true)
                ->first();

            if (! $package) {
                throw new DomainException('Paket kredit promosi tidak ditemukan atau sudah tidak aktif.');
            }

            $wallet = DB::table('wallets')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            $profile = DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            if (! $wallet || ! $profile) {
                throw new DomainException('Dompet atau profil toko penjual tidak ditemukan.');
            }

            $price = (float) $package->price;
            $creditsGranted = $package->totalCredits();
            $purchaseCode = 'ADC-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));

            $this->ledgerService->debitForAdCreditPurchase(
                sellerId: $sellerId,
                amount: $price,
                purchaseCode: $purchaseCode,
                packageName: $package->name,
                alreadyLocked: true,
            );

            DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->increment('ad_credits', $creditsGranted, ['updated_at' => now()]);

            $purchase = AdCreditPurchase::create([
                'purchase_code' => $purchaseCode,
                'seller_id' => $sellerId,
                'package_id' => $package->id,
                'credits_granted' => $creditsGranted,
                'amount_paid' => number_format($price, 2, '.', ''),
                'payment_method' => 'available_balance',
                'status' => 'PAID',
            ]);

            $this->notificationService->notify(
                userId: $sellerId,
                category: 'promo',
                title: "Paket {$package->name} Aktif (+{$creditsGranted} Kredit)",
                body: "Pembelian {$purchaseCode} sebesar Rp ".number_format($price, 0, ',', '.')." berhasil. Saldo kredit sundul toko Anda bertambah {$creditsGranted} kredit.",
                actionUrl: '/seller/ad-credits',
                refType: 'ad_credit_purchase',
                refId: (int) $purchase->id,
            );

            return $purchase->load('package');
        });
    }

    public function boostProductFeatured(
        int $sellerId,
        int $productId,
        int $creditCost = 3,
        int $durationDays = 7,
    ): Product {
        if ($creditCost <= 0) {
            throw new DomainException('Biaya kredit promosi harus minimal 1 kredit.');
        }

        if ($durationDays < 1) {
            throw new DomainException('Durasi promosi Featured minimal 1 hari.');
        }

        return DB::transaction(function () use ($sellerId, $productId, $creditCost, $durationDays): Product {
            $profile = DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            $productRow = DB::table('products')
                ->where('id', $productId)
                ->where('seller_id', $sellerId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $profile || ! $productRow) {
                throw new DomainException('Produk atau profil toko tidak ditemukan.');
            }

            if ((int) $profile->ad_credits < $creditCost) {
                throw new DomainException("Kredit promosi tidak mencukupi (tersedia {$profile->ad_credits}, dibutuhkan {$creditCost} kredit).");
            }

            $now = now();
            $currentFeaturedUntil = $productRow->featured_until ? Carbon::parse($productRow->featured_until) : null;
            $baseStart = ($currentFeaturedUntil && $currentFeaturedUntil->isFuture())
                ? $currentFeaturedUntil
                : $now;
            $endsAt = $baseStart->copy()->addDays($durationDays);

            DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->decrement('ad_credits', $creditCost, ['updated_at' => $now]);

            ProductBoost::create([
                'product_id' => $productId,
                'seller_id' => $sellerId,
                'boost_type' => 'featured',
                'credits_spent' => $creditCost,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => 'active',
            ]);

            DB::table('products')
                ->where('id', $productId)
                ->update([
                    'is_featured' => true,
                    'featured_until' => $endsAt,
                    'last_bumped_at' => $now,
                    'updated_at' => $now,
                ]);

            return Product::findOrFail($productId);
        });
    }

    /**
     * Sweep expired active product boosts and downgrade products.is_featured when no active featured boost remains.
     */
    public function sweepExpiredBoosts(): int
    {
        return DB::transaction(function (): int {
            $now = now();
            $expiredBoosts = DB::table('product_boosts')
                ->where('status', 'active')
                ->where('ends_at', '<=', $now)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($expiredBoosts->isEmpty()) {
                return 0;
            }

            $boostIds = $expiredBoosts->pluck('id')->all();
            $affectedProductIds = $expiredBoosts
                ->where('boost_type', 'featured')
                ->pluck('product_id')
                ->unique()
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            DB::table('product_boosts')
                ->whereIn('id', $boostIds)
                ->update([
                    'status' => 'expired',
                    'updated_at' => $now,
                ]);

            if (! empty($affectedProductIds)) {
                DB::table('products')
                    ->whereIn('id', $affectedProductIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($affectedProductIds as $productId) {
                    $latestActiveEnd = DB::table('product_boosts')
                        ->where('product_id', $productId)
                        ->where('boost_type', 'featured')
                        ->where('status', 'active')
                        ->where('ends_at', '>', $now)
                        ->max('ends_at');

                    if ($latestActiveEnd === null) {
                        DB::table('products')
                            ->where('id', $productId)
                            ->update([
                                'is_featured' => false,
                                'featured_until' => null,
                                'updated_at' => $now,
                            ]);
                    } else {
                        DB::table('products')
                            ->where('id', $productId)
                            ->update([
                                'is_featured' => true,
                                'featured_until' => $latestActiveEnd,
                                'updated_at' => $now,
                            ]);
                    }
                }
            }

            return count($boostIds);
        });
    }

    /**
     * Evaluate seller store tier with strict Anti-Gaming guards:
     * - Only COMPLETED orders with gross_amount >= Rp 10.000 qualify (blocks Rp 100 dummy orders).
     * - Enforces minimum unique buyers AND cumulative qualifying GMV + SLA delivery speed.
     *
     * @return array{
     *   level: int,
     *   tier_name: string,
     *   completed_orders: int,
     *   qualifying_orders: int,
     *   unique_buyers: int,
     *   qualifying_gmv: float,
     *   avg_delivery_minutes: float,
     *   commission_discount_percent: float,
     *   next_level_orders: ?int,
     *   target_orders: ?int,
     *   target_unique_buyers: ?int,
     *   target_gmv: ?float,
     *   target_sla_minutes: ?float,
     *   next_level_label: ?string,
     *   progress_percent: int
     * }
     */
    public function recalculateStoreLevel(int $sellerId, bool $persist = true): array
    {
        $profile = SellerProfile::where('user_id', $sellerId)->firstOrFail();

        $metrics = DB::table('orders')
            ->where('seller_id', $sellerId)
            ->where('status', 'COMPLETED')
            ->where('gross_amount', '>=', 10000)
            ->selectRaw('COUNT(*) AS qualifying_orders')
            ->selectRaw('COUNT(DISTINCT buyer_id) AS unique_buyers')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS qualifying_gmv')
            ->first();

        $qualifyingOrders = (int) ($metrics->qualifying_orders ?? 0);
        $uniqueBuyers = (int) ($metrics->unique_buyers ?? 0);
        $qualifyingGmv = round((float) ($metrics->qualifying_gmv ?? 0), 2);
        $avgMinutes = (float) $profile->avg_delivery_minutes;

        if (
            $qualifyingOrders >= 100
            && $uniqueBuyers >= 35
            && $qualifyingGmv >= 15000000.0
            && $avgMinutes <= 8.0
        ) {
            $level = 3;
            $tierName = 'Juragan Sultan';
            $discount = 1.0;
            $targetOrders = null;
            $targetBuyers = null;
            $targetGmv = null;
            $targetSla = null;
            $nextLabel = null;
            $progress = 100;
        } elseif (
            $qualifyingOrders >= 25
            && $uniqueBuyers >= 10
            && $qualifyingGmv >= 2500000.0
            && $avgMinutes <= 10.0
        ) {
            $level = 2;
            $tierName = 'Juragan Pro';
            $discount = 0.5;
            $targetOrders = 100;
            $targetBuyers = 35;
            $targetGmv = 15000000.0;
            $targetSla = 8.0;
            $nextLabel = 'Juragan Sultan (Diskon Komisi 1.0%)';
            $pOrders = min(100, ($qualifyingOrders / 100) * 100);
            $pBuyers = min(100, ($uniqueBuyers / 35) * 100);
            $pGmv = min(100, ($qualifyingGmv / 15000000.0) * 100);
            $progress = min(100, (int) round(($pOrders + $pBuyers + $pGmv) / 3));
        } else {
            $level = 1;
            $tierName = 'Juragan Baru';
            $discount = 0.0;
            $targetOrders = 25;
            $targetBuyers = 10;
            $targetGmv = 2500000.0;
            $targetSla = 10.0;
            $nextLabel = 'Juragan Pro (Diskon Komisi 0.5%)';
            $pOrders = min(100, ($qualifyingOrders / 25) * 100);
            $pBuyers = min(100, ($uniqueBuyers / 10) * 100);
            $pGmv = min(100, ($qualifyingGmv / 2500000.0) * 100);
            $progress = min(100, (int) round(($pOrders + $pBuyers + $pGmv) / 3));
        }

        if ($persist && (int) $profile->store_level !== $level) {
            $profile->update(['store_level' => $level]);
        }

        return [
            'level' => $level,
            'tier_name' => $tierName,
            'completed_orders' => $qualifyingOrders,
            'qualifying_orders' => $qualifyingOrders,
            'unique_buyers' => $uniqueBuyers,
            'qualifying_gmv' => $qualifyingGmv,
            'avg_delivery_minutes' => $avgMinutes,
            'commission_discount_percent' => $discount,
            'next_level_orders' => $targetOrders,
            'target_orders' => $targetOrders,
            'target_unique_buyers' => $targetBuyers,
            'target_gmv' => $targetGmv,
            'target_sla_minutes' => $targetSla,
            'next_level_label' => $nextLabel,
            'progress_percent' => $progress,
        ];
    }

    public function effectiveCommissionPercent(int $sellerId, float $categoryBaseFeePercent): float
    {
        $meta = $this->recalculateStoreLevel($sellerId, false);
        $discount = (float) $meta['commission_discount_percent'];

        if ($discount <= 0.0) {
            return $categoryBaseFeePercent;
        }

        return max(1.0, round($categoryBaseFeePercent - $discount, 2));
    }
}
