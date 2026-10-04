<?php

use App\Application\Card\BatchCardTransactionSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('topups:scan-trc20')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('promotion:recover')->everyMinute()->withoutOverlapping(30)->onOneServer();
Schedule::command('deposits:process-refunds')->everyMinute()->withoutOverlapping(30)->onOneServer();

Schedule::command('assets:scan ETHEREUM')->everyMinute()->withoutOverlapping(30)->onOneServer();
Schedule::command('assets:scan BITCOIN')->everyMinute()->withoutOverlapping(30)->onOneServer();

Schedule::command('wealth:recover')->everyMinute()->withoutOverlapping(30)->onOneServer();

Schedule::command('messages:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();

Schedule::command('images:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();

Schedule::command('images:replicate --limit=20')->everyMinute()->withoutOverlapping(10)->onOneServer();

Artisan::command('cards:recover-transaction-sync', function () {
    app(BatchCardTransactionSync::class)->recover();
})->purpose('Resume outstanding explicit card transaction sync batches');
Schedule::command('cards:recover-transaction-sync')->everyMinute()->withoutOverlapping(5)->onOneServer();
