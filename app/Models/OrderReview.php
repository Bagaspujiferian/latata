<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReview extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'product_id',
        'seller_id',
        'buyer_id',
        'rating',
        'comment',
        'seller_reply',
        'replied_at',
        'delivery_duration_minutes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'delivery_duration_minutes' => 'integer',
            'replied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }
}
