<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCreditPackage extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'credits_amount',
        'bonus_credits',
        'price',
        'badge_label',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credits_amount' => 'integer',
            'bonus_credits' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(AdCreditPurchase::class, 'package_id', 'id');
    }

    public function totalCredits(): int
    {
        return (int) $this->credits_amount + (int) $this->bonus_credits;
    }
}
