<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_role',
        'attached_product_id',
        'attached_order_id',
        'raw_message',
        'sanitized_message',
        'is_flagged_bypass',
        'detected_violation_type',
        'read_at',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_flagged_bypass' => 'boolean',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id', 'id');
    }

    /** @return BelongsTo<Product, $this> */
    public function attachedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'attached_product_id', 'id')->withTrashed();
    }

    /** @return BelongsTo<Order, $this> */
    public function attachedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'attached_order_id', 'id');
    }
}
