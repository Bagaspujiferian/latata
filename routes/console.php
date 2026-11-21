<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('nexatrade:sweep-expired')->everyMinute();
Schedule::command('nexatrade:settle-completed')->everyMinute();
Schedule::command('nexatrade:reconcile-h2h --minutes=5')->everyFiveMinutes();
Schedule::command('nexatrade:audit-ledger --triggered-by=scheduler')->hourly();
