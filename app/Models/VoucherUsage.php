<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherUsage extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'voucher_id',
        'user_id',
        'payment_invoice_id',
        'order_id',
        'subsidy_bearer',
        'discount_amount',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentInvoice(): BelongsTo
    {
        return $this->belongsTo(PaymentInvoice::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
