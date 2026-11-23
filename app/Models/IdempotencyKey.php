<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $response_snapshot
 */
class IdempotencyKey extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'idempotency_key',
        'http_method',
        'route_path',
        'payload_hash',
        'status',
        'http_status',
        'response_snapshot',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'response_snapshot' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
