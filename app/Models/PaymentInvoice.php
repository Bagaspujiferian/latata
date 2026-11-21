<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentInvoice extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'invoice_code',
        'buyer_id',
        'subtotal_amount',
        'seller_discount_total',
        'platform_discount_total',
        'gateway_fee',
        'wallet_used_amount',
        'grand_total',
        'payment_channel',
        'snap_token',
        'midtrans_transaction_id',
        'status',
        'expires_at',
        'paid_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'decimal:2',
            'seller_discount_total' => 'decimal:2',
            'platform_discount_total' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'wallet_used_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'payment_invoice_id', 'id');
    }

    public function walletMutations(): HasMany
    {
        return $this->hasMany(WalletMutation::class, 'payment_invoice_id', 'id');
    }
}
