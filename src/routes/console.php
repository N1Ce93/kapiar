<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('parser:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('telegram-channels:check')->everyFiveMinutes()->withoutOverlapping();
