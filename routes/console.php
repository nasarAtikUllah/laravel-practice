<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:release-expired')->everyMinute()->withoutOverlapping();
Schedule::command('inventory:reconcile')->dailyAt('02:00');
Schedule::command('coupons:release-expired')->everyMinute()->withoutOverlapping();
Schedule::command('coupons:reconcile')->dailyAt('02:10');
