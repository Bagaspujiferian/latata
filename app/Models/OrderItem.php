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
        'flash_sale_item_id',
        'product_title_snapshot',
        'sku_snapshot',
        'unit_price_snapshot',
        'original_unit_price',
        'quantity',
        'subtotal',
        'encrypted_delivery_payload',
    ];

    /** @var list<string> */
    protected $hidden = [
        'encrypted_delivery_payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'original_unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductVault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(ProductVault::class, 'product_vault_id', 'id');
    }

    /** @return BelongsTo<FlashSaleItem, $this> */
    public function flashSaleItem(): BelongsTo
    {
        return $this->belongsTo(FlashSaleItem::class);
    }
}
