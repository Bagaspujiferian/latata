<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'buyer_id',
        'seller_id',
        'context_product_id',
        'context_order_id',
        'buyer_unread_count',
        'seller_unread_count',
        'last_message_preview',
        'last_message_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'buyer_unread_count' => 'integer',
            'seller_unread_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id', 'user_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function contextProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'context_product_id', 'id')->withTrashed();
    }

    /** @return BelongsTo<Order, $this> */
    public function contextOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'context_order_id', 'id');
    }

    /** @return HasMany<ConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id', 'asc');
    }

    public function unreadCountFor(int $userId): int
    {
        if ((int) $this->buyer_id === $userId) {
            return (int) $this->buyer_unread_count;
        }

        if ((int) $this->seller_id === $userId) {
            return (int) $this->seller_unread_count;
        }

        return 0;
    }
}
