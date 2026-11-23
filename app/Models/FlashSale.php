<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashSale extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'banner_subtitle',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /** @return HasMany<FlashSaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FlashSaleItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isLiveNow(): bool
    {
        $now = now();

        return $this->is_active && $this->starts_at <= $now && $this->ends_at >= $now;
    }

    public function isRunningNow(): bool
    {
        return $this->isLiveNow();
    }
}
