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

    /** @var list<string> */
    protected $hidden = [
        'encrypted_payload',
        'payload_blind_hash',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $vault): void {
            $originalStatus = strtolower((string) $vault->getOriginal('status'));
            if ($originalStatus === 'sold') {
                throw new \DomainException('Baris brankas berstatus SOLD bersifat permanen dan tidak dapat diubah.');
            }

            if ($originalStatus === 'reserved' && $vault->isDirty(['encrypted_payload', 'payload_blind_hash'])) {
                throw new \DomainException('Payload brankas yang sedang direservasi (RESERVED) tidak dapat diubah.');
            }
        });

        static::deleting(function (self $vault): void {
            $originalStatus = strtolower((string) $vault->getOriginal('status'));
            if ($originalStatus !== 'available') {
                throw new \DomainException('Baris brankas berstatus RESERVED atau SOLD tidak dapat dihapus.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reserved_until' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function reservedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reserved_order_id', 'id');
    }
}
