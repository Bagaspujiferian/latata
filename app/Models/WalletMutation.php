<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletMutation extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Ledger/Audit records are strictly immutable and append-only.');
        });

        static::deleting(function (): void {
            throw new \LogicException('Ledger/Audit records are strictly immutable and append-only.');
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'reference_code',
        'user_id',
        'order_id',
        'payment_invoice_id',
        'balance_bucket',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'mutation_type',
        'description',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentInvoice(): BelongsTo
    {
        return $this->belongsTo(PaymentInvoice::class);
    }
}
