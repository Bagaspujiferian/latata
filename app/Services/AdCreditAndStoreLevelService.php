<?php

namespace App\Services;

use App\Models\AdCreditPackage;
use App\Models\AdCreditPurchase;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use DomainException;
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

    public function boostProductFeatured(int $sellerId, int $productId, int $creditCost = 3): Product
    {
        if ($creditCost <= 0) {
            throw new DomainException('Biaya kredit promosi harus minimal 1 kredit.');
        }

        return DB::transaction(function () use ($sellerId, $productId, $creditCost): Product {
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

            DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->decrement('ad_credits', $creditCost, ['updated_at' => now()]);

            DB::table('products')
                ->where('id', $productId)
                ->update([
                    'is_featured' => true,
                    'last_bumped_at' => now(),
                    'updated_at' => now(),
                ]);

            return Product::findOrFail($productId);
        });
    }

    /**
     * Evaluate seller store tier strictly from verified COMPLETED orders & SLA delivery speed.
     *
     * @return array{
     *   level: int,
     *   tier_name: string,
     *   completed_orders: int,
     *   avg_delivery_minutes: float,
     *   commission_discount_percent: float,
     *   next_level_orders: ?int,
     *   next_level_label: ?string,
     *   progress_percent: int
     * }
     */
    public function recalculateStoreLevel(int $sellerId, bool $persist = true): array
    {
        $profile = SellerProfile::where('user_id', $sellerId)->firstOrFail();
        $completedOrders = Order::where('seller_id', $sellerId)->where('status', 'COMPLETED')->count();
        $avgMinutes = (float) $profile->avg_delivery_minutes;

        if ($completedOrders >= 100 && $avgMinutes <= 8.0) {
            $level = 3;
            $tierName = 'Juragan Sultan';
            $discount = 1.0;
            $nextTarget = null;
            $nextLabel = null;
            $progress = 100;
        } elseif ($completedOrders >= 25 && $avgMinutes <= 10.0) {
            $level = 2;
            $tierName = 'Juragan Pro';
            $discount = 0.5;
            $nextTarget = 100;
            $nextLabel = 'Juragan Sultan (Diskon Komisi 1.0%)';
            $progress = min(100, (int) round(($completedOrders / 100) * 100));
        } else {
            $level = 1;
            $tierName = 'Juragan Baru';
            $discount = 0.0;
            $nextTarget = 25;
            $nextLabel = 'Juragan Pro (Diskon Komisi 0.5%)';
            $progress = min(100, (int) round(($completedOrders / 25) * 100));
        }

        if ($persist && (int) $profile->store_level !== $level) {
            $profile->update(['store_level' => $level]);
        }

        return [
            'level' => $level,
            'tier_name' => $tierName,
            'completed_orders' => $completedOrders,
            'avg_delivery_minutes' => $avgMinutes,
            'commission_discount_percent' => $discount,
            'next_level_orders' => $nextTarget,
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
