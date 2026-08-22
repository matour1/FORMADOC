<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Q1a — Renouvellement automatique des abonnements (cron quotidien)
Schedule::command('subscriptions:renew')
    ->dailyAt('06:00')
    ->withoutOverlapping();
