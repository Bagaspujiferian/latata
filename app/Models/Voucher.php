<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'title',
        'issuer_type',
        'seller_id',
        'game_id',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_purchase_amount',
        'quota_total',
        'quota_used',
        'max_uses_per_user',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_purchase_amount' => 'decimal:2',
            'quota_total' => 'integer',
            'quota_used' => 'integer',
            'max_uses_per_user' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /** @return HasMany<VoucherUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function remainingQuota(): int
    {
        return max(0, (int) $this->quota_total - (int) $this->quota_used);
    }

    public function isCurrentlyValid(): bool
    {
        $now = now();

        return $this->is_active
            && $this->remainingQuota() > 0
            && $this->starts_at <= $now
            && $this->ends_at >= $now;
    }
}
