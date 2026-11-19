<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mailform:deliver')->everyMinute()->withoutOverlapping(60);
Schedule::command('mailform:prune')->everyMinute()->withoutOverlapping(60);
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put('mailform.scheduler', now()->toDateTimeString(), 300))
    ->name('mailform-heartbeat')->everyMinute();
