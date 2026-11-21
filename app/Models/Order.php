<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'order_code',
        'payment_invoice_id',
        'buyer_id',
        'seller_id',
        'gross_amount',
        'platform_commission',
        'seller_escrow_amount',
        'delivery_method',
        'status',
        'buyer_game_inputs',
        'verified_game_nickname',
        'seller_delivery_proof',
        'h2h_reference_id',
        'h2h_response_log',
        'sla_target_minutes_snapshot',
        'sla_deadline_at',
        'delivered_at',
        'auto_complete_at',
        'completed_at',
        'cancelled_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'platform_commission' => 'decimal:2',
            'seller_escrow_amount' => 'decimal:2',
            'buyer_game_inputs' => 'array',
            'seller_delivery_proof' => 'array',
            'h2h_response_log' => 'array',
            'sla_target_minutes_snapshot' => 'integer',
            'sla_deadline_at' => 'datetime',
            'delivered_at' => 'datetime',
            'auto_complete_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function paymentInvoice(): BelongsTo
    {
        return $this->belongsTo(PaymentInvoice::class, 'payment_invoice_id', 'id');
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(OrderReview::class);
    }

    public function walletMutations(): HasMany
    {
        return $this->hasMany(WalletMutation::class);
    }

    public function isSlaBreached(): bool
    {
        return $this->status === 'PROCESSING'
            && $this->sla_deadline_at !== null
            && now()->greaterThan($this->sla_deadline_at);
    }
}
