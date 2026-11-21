<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashSaleItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'flash_sale_id',
        'product_id',
        'flash_price',
        'flash_quota',
        'flash_sold',
        'max_qty_per_user',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'flash_price' => 'decimal:2',
            'flash_quota' => 'integer',
            'flash_sold' => 'integer',
            'max_qty_per_user' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function remainingQuota(): int
    {
        return max(0, (int) $this->flash_quota - (int) $this->flash_sold);
    }

    public function soldPercentage(): int
    {
        if ($this->flash_quota <= 0) {
            return 100;
        }

        return min(100, (int) round(($this->flash_sold / $this->flash_quota) * 100));
    }

    public function quotaProgressPercent(): int
    {
        return $this->soldPercentage();
    }
}
