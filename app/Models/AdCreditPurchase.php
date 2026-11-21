<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreditPurchase extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_code',
        'seller_id',
        'package_id',
        'credits_granted',
        'amount_paid',
        'payment_method',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credits_granted' => 'integer',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(AdCreditPackage::class, 'package_id', 'id');
    }
}
