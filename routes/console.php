<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('nexatrade:sweep-expired')->everyMinute();
Schedule::command('nexatrade:settle-completed')->everyMinute();
