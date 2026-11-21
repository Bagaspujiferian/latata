<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_vault_id',
        'product_title_snapshot',
        'sku_snapshot',
        'unit_price_snapshot',
        'quantity',
        'subtotal',
        'encrypted_delivery_payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
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

    public function vault(): BelongsTo
    {
        return $this->belongsTo(ProductVault::class, 'product_vault_id', 'id');
    }
}
