<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserBankAccount extends Model
{
    use SoftDeletes;

    /** @var array<string, string> */
    public const SUPPORTED_BANKS = [
        'BCA' => 'Bank Central Asia (BCA)',
        'BRI' => 'Bank Rakyat Indonesia (BRI)',
        'BNI' => 'Bank Negara Indonesia (BNI)',
        'MANDIRI' => 'Bank Mandiri',
        'JAGO' => 'Bank Jago',
        'GOPAY' => 'GoPay',
        'DANA' => 'DANA',
        'OVO' => 'OVO',
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'bank_code',
        'bank_name',
        'account_number',
        'account_holder_name',
        'is_verified',
        'is_primary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'user_bank_account_id', 'id');
    }
}
