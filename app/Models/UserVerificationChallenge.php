<?php

namespace App\Models;

use App\Enums\VerificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property VerificationChannel $channel
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class UserVerificationChallenge extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'channel',
        'target_hash',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => VerificationChannel::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
