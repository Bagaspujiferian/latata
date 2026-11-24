<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $status
 * @property Carbon|null $processed_at
 */
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by', 'id');
    }

    /** @return BelongsTo<UserBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(UserBankAccount::class, 'user_bank_account_id', 'id')->withTrashed();
    }

    /** @return HasMany<WithdrawalApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(WithdrawalApproval::class, 'withdrawal_id', 'id')->orderBy('id', 'asc');
    }
}
