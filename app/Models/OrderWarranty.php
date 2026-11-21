<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderWarranty extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'buyer_id',
        'seller_id',
        'warranty_days',
        'coverage_amount',
        'starts_at',
        'expires_at',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warranty_days' => 'integer',
            'coverage_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class)->orderByDesc('id');
    }

    public function isClaimableNow(): bool
    {
        return $this->status === 'active' && now()->lessThanOrEqualTo($this->expires_at);
    }

    public function isClaimable(): bool
    {
        return $this->isClaimableNow();
    }
}
