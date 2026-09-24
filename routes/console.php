<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily expiry reminders (needs the scheduler: `php artisan schedule:work`, or a cron running `schedule:run`)
Illuminate\Support\Facades\Schedule::command('notifications:expiring-documents')->dailyAt('08:00');
