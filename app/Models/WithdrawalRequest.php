<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'withdraw_code',
        'user_id',
        'user_bank_account_id',
        'bank_name',
        'account_number',
        'account_holder',
        'amount',
        'admin_fee',
        'net_payout',
        'status',
        'processed_by',
        'rejection_reason',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'net_payout' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by', 'id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(UserBankAccount::class, 'user_bank_account_id', 'id')->withTrashed();
    }
}
