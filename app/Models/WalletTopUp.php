<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $paid_at
 * @property Carbon|null $expires_at
 */
class WalletTopUp extends Model
{
    protected $table = 'wallet_topups';

    /** @var list<string> */
    protected $fillable = [
        'topup_code',
        'user_id',
        'amount',
        'payment_channel',
        'gateway_payment_type',
        'gateway_mdr_fee',
        'gateway_reference',
        'snap_token',
        'snap_redirect_url',
        'status',
        'paid_at',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_mdr_fee' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
