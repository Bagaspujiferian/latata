<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVault extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'payload_blind_hash',
        'encrypted_payload',
        'status',
        'reserved_order_id',
        'reserved_until',
        'sold_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reserved_until' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reserved_order_id', 'id');
    }
}
