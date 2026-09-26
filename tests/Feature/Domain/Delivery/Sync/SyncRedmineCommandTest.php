<?php

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\RedmineIssue;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Domain\Delivery\Sync\FakeRedmine;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.key' => 'secret-key']);
    Sleep::fake();
    $this->redmine = new FakeRedmine;
    $this->redmine->issues = [FakeRedmine::issue(1)];
    $this->redmine->timeEntries = [FakeRedmine::timeEntry(1)];
});

test('it syncs issues and time entries and prints stats', function () {
    $this->artisan('infolink:sync-redmine')
        ->expectsOutputToContain('Redmine 議題: 成功')
        ->expectsOutputToContain('Redmine 工時: 成功')
        ->assertSuccessful();

    expect(RedmineIssue::count())->toBe(1)
        ->and(RedmineTimeEntry::count())->toBe(1)
        ->and(SyncRun::where('status', SyncStatus::Ok)->count())->toBe(2);
});

test('--issues-only and --time-only limit what is synced', function () {
    $this->artisan('infolink:sync-redmine --issues-only')->assertSuccessful();
    expect(SyncRun::pluck('job')->all())->toBe([SyncJob::RedmineIssues]);

    $this->artisan('infolink:sync-redmine --time-only')->assertSuccessful();
    expect(SyncRun::where('job', SyncJob::RedmineTime)->count())->toBe(1)
        ->and(SyncRun::count())->toBe(2);
});

test('--full forces a full issue fetch after a previous ok run', function () {
    $this->artisan('infolink:sync-redmine --issues-only')->assertSuccessful();
    $this->artisan('infolink:sync-redmine --issues-only --full')->assertSuccessful();

    expect(SyncRun::latestFor(SyncJob::RedmineIssues)->stats['mode'])->toBe('full');
});

test('it exits 1 when Redmine is unreachable', function () {
    $this->redmine->unreachable = true;

    $this->artisan('infolink:sync-redmine')
        ->expectsOutputToContain('Redmine unreachable')
        ->assertFailed();

    expect(SyncRun::where('status', SyncStatus::Failed)->count())->toBe(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'redmine.test'));
});

test('it is scheduled hourly on weekdays and fully on sundays, without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'infolink:sync-redmine'))
        ->values();

    expect($events)->toHaveCount(2);

    [$hourly, $weekly] = str_contains((string) $events[0]->command, '--full') ? [$events[1], $events[0]] : [$events[0], $events[1]];

    expect($hourly->expression)->toBe('0 * * * 1-5')
        ->and($hourly->withoutOverlapping)->toBeTrue()
        ->and($weekly->expression)->toBe('0 3 * * 0')
        ->and($weekly->withoutOverlapping)->toBeTrue();
});
