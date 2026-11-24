<?php

namespace App\Enums;

enum HeartbeatRunStatus: string
{
    case RUNNING = 'running';
    case OK = 'ok';
    case FAILED = 'failed';
}
