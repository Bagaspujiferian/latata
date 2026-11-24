<?php

namespace App\Models;

use App\Enums\HeartbeatRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property HeartbeatRunStatus $last_status
 * @property Carbon|null $last_started_at
 * @property Carbon|null $last_finished_at
 * @property int $expected_interval_minutes
 * @property int|null $last_duration_ms
 * @property int $consecutive_failures
 */
class SchedulerHeartbeat extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'command_name',
        'display_name',
        'expected_interval_minutes',
        'last_started_at',
        'last_finished_at',
        'last_status',
        'last_duration_ms',
        'last_summary',
        'last_error',
        'consecutive_failures',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_interval_minutes' => 'integer',
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
            'last_status' => HeartbeatRunStatus::class,
            'last_duration_ms' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }
}
