<?php

namespace App\Services;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use Illuminate\Support\Facades\DB;

class FlashSaleService
{
    public function getActiveFlashSale(): ?FlashSale
    {
        $now = now();

        return FlashSale::with([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items.product.images',
            'items.product.gameCategory.game',
            'items.product.sellerProfile',
        ])
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('ends_at', 'asc')
            ->first();
    }

    public function getActiveFlashItemForProduct(int $productId): ?FlashSaleItem
    {
        $now = now();

        return FlashSaleItem::with('flashSale')
            ->where('product_id', $productId)
            ->whereHas('flashSale', function ($q) use ($now): void {
                $q->where('is_active', true)
                    ->where('starts_at', '<=', $now)
                    ->where('ends_at', '>=', $now);
            })
            ->orderBy('flash_price', 'asc')
            ->first();
    }

    /**
     * Lock active flash_sale_items row inside CheckoutSplitOrderService transaction, verify quota & per-user limit,
     * increment flash_sold atomically, or gracefully fall back to regular price if quota/limit reached.
     *
     * @return array{flash_sale_item_id: int, unit_price: float, original_unit_price: float}|null
     */
    public function resolveAndLockFlashPriceForCheckout(int $productId, int $buyerId, int $quantity, float $regularPrice): ?array
    {
        $now = now();

        $candidateId = DB::table('flash_sale_items')
            ->join('flash_sales', 'flash_sales.id', '=', 'flash_sale_items.flash_sale_id')
            ->where('flash_sale_items.product_id', $productId)
            ->where('flash_sales.is_active', true)
            ->where('flash_sales.starts_at', '<=', $now)
            ->where('flash_sales.ends_at', '>=', $now)
            ->orderBy('flash_sale_items.flash_price', 'asc')
            ->value('flash_sale_items.id');

        if (! $candidateId) {
            return null;
        }

        $lockedItem = DB::table('flash_sale_items')
            ->where('id', $candidateId)
            ->lockForUpdate()
            ->first();

        if (! $lockedItem) {
            return null;
        }

        $remainingQuota = (int) $lockedItem->flash_quota - (int) $lockedItem->flash_sold;
        if ($remainingQuota < $quantity) {
            return null;
        }

        $priorBoughtByUser = (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.flash_sale_item_id', $lockedItem->id)
            ->where('orders.buyer_id', $buyerId)
            ->whereNotIn('orders.status', ['CANCELLED'])
            ->sum('order_items.quantity');

        if (($priorBoughtByUser + $quantity) > (int) $lockedItem->max_qty_per_user) {
            return null;
        }

        DB::table('flash_sale_items')
            ->where('id', $lockedItem->id)
            ->increment('flash_sold', $quantity);

        return [
            'flash_sale_item_id' => (int) $lockedItem->id,
            'unit_price' => (float) $lockedItem->flash_price,
            'original_unit_price' => $regularPrice,
        ];
    }

    /**
     * Release reserved flash_sold quota when an unpaid invoice expires or cancels.
     */
    public function releaseFlashQuotaForCancelledOrder(int $orderId): int
    {
        $items = DB::table('order_items')
            ->where('order_id', $orderId)
            ->whereNotNull('flash_sale_item_id')
            ->orderBy('flash_sale_item_id', 'asc')
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $qtyByFlashId = [];
        foreach ($items as $item) {
            $fid = (int) $item->flash_sale_item_id;
            $qtyByFlashId[$fid] = ($qtyByFlashId[$fid] ?? 0) + (int) $item->quantity;
        }

        $flashIds = array_keys($qtyByFlashId);
        sort($flashIds, SORT_NUMERIC);

        $lockedRows = DB::table('flash_sale_items')
            ->whereIn('id', $flashIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $releasedTotal = 0;
        foreach ($flashIds as $fid) {
            $row = $lockedRows->get($fid);
            if (! $row) {
                continue;
            }
            $newSold = max(0, (int) $row->flash_sold - $qtyByFlashId[$fid]);
            DB::table('flash_sale_items')
                ->where('id', $fid)
                ->update([
                    'flash_sold' => $newSold,
                    'updated_at' => now(),
                ]);
            $releasedTotal += $qtyByFlashId[$fid];
        }

        return $releasedTotal;
    }
}
