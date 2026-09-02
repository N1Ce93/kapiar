<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sources:dispatch-checks --sites-limit=15 --limit=20')->everyTenMinutes()->withoutOverlapping(15);
Schedule::command('telegram-channels:dispatch-checks --channels-limit=20 --limit=5')->everyTenMinutes()->withoutOverlapping(15);
Schedule::command('gmail:dispatch-check')
    ->cron('0 8,10,12,14,16 * * *')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping(20)
    ->onOneServer();
Schedule::command('reports:send-monthly')
    ->monthlyOn(1, '08:00')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping(120)
    ->onOneServer();
