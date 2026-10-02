<?php

use App\Domain\Insights\InsightService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('infolink:snapshot-finance')->dailyAt('07:00');
Schedule::command('infolink:snapshot-sales')->dailyAt('07:05');
Schedule::call(fn () => app(InsightService::class)->expireStale())->name('infolink:expire-insights')->hourly();
Schedule::command('infolink:sync-redmine')->weekdays()->hourly()->between('08:00', '20:00')->withoutOverlapping();
Schedule::command('infolink:sync-redmine --full')->sundays()->at('03:00')->withoutOverlapping();
Schedule::command('infolink:snapshot-redmine')->dailyAt('23:50')->withoutOverlapping();
Schedule::command('infolink:flush-notifications')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('infolink:evaluate-rules')->hourly()->withoutOverlapping();
Schedule::command('infolink:collect-server-disks')->hourlyAt(15)->withoutOverlapping();
Schedule::command('infolink:sync-github')->weekdays()->hourlyAt(20)->between('08:00', '21:00')->withoutOverlapping();
Schedule::command('infolink:sync-github --full')->sundays()->at('03:30')->withoutOverlapping();
