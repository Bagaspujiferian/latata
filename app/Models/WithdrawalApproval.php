<?php

namespace App\Models;

use App\Enums\WithdrawalApprovalStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property WithdrawalApprovalStage $stage
 * @property Carbon $decided_at
 * @property Carbon|null $created_at
 */
class WithdrawalApproval extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'withdrawal_id',
        'admin_id',
        'stage',
        'decision',
        'notes',
        'decided_at',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stage' => WithdrawalApprovalStage::class,
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WithdrawalRequest, $this> */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class, 'withdrawal_id', 'id');
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }
}
