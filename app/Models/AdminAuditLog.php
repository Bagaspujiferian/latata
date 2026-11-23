<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $before_state
 * @property array<string, mixed>|null $after_state
 */
class AdminAuditLog extends Model
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
        'admin_id',
        'action',
        'entity_type',
        'entity_id',
        'before_state',
        'after_state',
        'ip_address',
        'notes',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }
}
