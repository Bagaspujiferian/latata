<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int|string, mixed>|null $discrepancy_details
 */
class LedgerAuditSnapshot extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'audit_code',
        'status',
        'total_wallets_pending',
        'total_wallets_available',
        'total_wallets_deposit',
        'total_custody_wallets',
        'total_ledger_net',
        'discrepancy_amount',
        'users_checked',
        'discrepancy_details',
        'triggered_by',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_wallets_pending' => 'decimal:2',
            'total_wallets_available' => 'decimal:2',
            'total_wallets_deposit' => 'decimal:2',
            'total_custody_wallets' => 'decimal:2',
            'total_ledger_net' => 'decimal:2',
            'discrepancy_amount' => 'decimal:2',
            'users_checked' => 'integer',
            'discrepancy_details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
