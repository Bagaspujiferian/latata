<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public const CREATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'pending_balance',
        'available_balance',
        'security_deposit_balance',
        'clawback_debt_balance',
        'updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'security_deposit_balance' => 'decimal:2',
            'clawback_debt_balance' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function mutations(): HasMany
    {
        return $this->hasMany(WalletMutation::class, 'user_id', 'user_id');
    }
}
