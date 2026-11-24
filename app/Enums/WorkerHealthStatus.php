<?php

namespace App\Enums;

enum WorkerHealthStatus: string
{
    case HEALTHY = 'healthy';
    case STALE = 'stale';
    case FAILED = 'failed';
    case RUNNING = 'running';
    case NEVER_RUN = 'never_run';

    public function label(): string
    {
        return match ($this) {
            self::HEALTHY => 'HEALTHY',
            self::STALE => 'STALE (OVERDUE)',
            self::FAILED => 'FAILED',
            self::RUNNING => 'RUNNING',
            self::NEVER_RUN => 'NEVER RUN',
        };
    }
}
