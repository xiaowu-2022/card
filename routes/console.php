<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('topups:scan-trc20')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('cards:recover')->everyMinute()->withoutOverlapping(30)->onOneServer();
Schedule::command('promotion:recover')->everyMinute()->withoutOverlapping(30)->onOneServer();
Schedule::command('deposits:process-refunds')->everyMinute()->withoutOverlapping(30)->onOneServer();
