<?php

use App\Domain\Insights\InsightService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('infolink:snapshot-finance')->dailyAt('07:00');
Schedule::call(fn () => app(InsightService::class)->expireStale())->name('infolink:expire-insights')->hourly();
