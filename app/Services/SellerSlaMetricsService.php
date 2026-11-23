<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerProfile;
use Illuminate\Support\Facades\DB;

class SellerSlaMetricsService
{
    public function recalculateSellerSla(int $sellerId): ?SellerProfile
    {
        $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->first();
        if (! $profile) {
            return null;
        }

        $recentOrders = Order::with('paymentInvoice')
            ->where('seller_id', $sellerId)
            ->whereIn('status', ['DELIVERED', 'COMPLETED'])
            ->whereNotNull('delivered_at')
            ->orderByDesc('delivered_at')
            ->limit(20)
            ->get();

        $avgMinutes = (float) $profile->avg_delivery_minutes;

        if ($recentOrders->isNotEmpty()) {
            $durations = [];
            foreach ($recentOrders as $order) {
                $start = $order->paymentInvoice->paid_at ?? $order->created_at ?? now();
                $seconds = max(3, $start->diffInSeconds($order->delivered_at, false));
                $durations[] = round($seconds / 60, 2);
            }
            $avgMinutes = round(array_sum($durations) / count($durations), 2);
        }

        $totalTracked = $profile->total_completed_orders + $profile->total_cancelled_orders;
        $cancelRatio = $totalTracked > 0 ? ($profile->total_cancelled_orders / $totalTracked) : 0.0;
        $threshold = (float) config('nexatrade.sla_badge_threshold_minutes', 10.00);

        $isSlaBadgeActive = ($avgMinutes <= $threshold) && ($cancelRatio <= 0.25);

        DB::table('seller_profiles')
            ->where('user_id', $sellerId)
            ->update([
                'avg_delivery_minutes' => number_format($avgMinutes, 2, '.', ''),
                'is_sla_10m_badge' => $isSlaBadgeActive,
                'updated_at' => now(),
            ]);

        return $profile->fresh();
    }
}
