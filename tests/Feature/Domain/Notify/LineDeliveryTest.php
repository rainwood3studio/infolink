<?php

use App\Domain\Notify\Notifier;
use App\Enums\InsightSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendLineMessage;
use App\Models\Insight;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.line.channel_access_token' => 'test-token',
        'services.line.user_id' => 'U-owner',
    ]);
    Http::preventStrayRequests();
});

function fakeLine(mixed $response): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.line.me/*' => $response]);
}

test('LINE without a token or user id is logged as skipped and never called', function (string $missing) {
    config(["services.line.{$missing}" => '']);
    fakeLine(Http::response([], 200));

    $insight = Insight::factory()->create(['severity' => InsightSeverity::Critical]);

    Http::assertNothingSent();
    expect(NotificationLog::query()->where('channel', NotificationChannel::Line)->sole())
        ->status->toBe(NotificationStatus::Skipped)
        ->error->toBe('LINE not configured')
        ->insight_id->toBe($insight->id)
        ->payload->toHaveKey('text');
})->with(['channel_access_token', 'user_id']);

test('a LINE 5xx is logged as failed and the job is released for a retry', function () {
    fakeLine(Http::response(['message' => 'oops'], 500));

    $job = (new SendLineMessage('測試', insightId: null))->withFakeQueueInteractions();
    $job->handle(app(Notifier::class));

    $job->assertReleased(60);
    expect(NotificationLog::query()->sole())
        ->status->toBe(NotificationStatus::Failed)
        ->error->toContain('HTTP 500')
        ->sent_at->toBeNull();
});

test('a connection error is retried, with longer backoff on the second attempt', function () {
    fakeLine(fn () => throw new ConnectionException('timed out'));

    $job = (new SendLineMessage('測試'))->withFakeQueueInteractions();
    $job->job->attempts = 2;
    $job->handle(app(Notifier::class));

    $job->assertReleased(300);
    expect(NotificationLog::query()->sole()->error)->toContain('LINE unreachable: timed out');
});

test('the last attempt is not released again', function () {
    fakeLine(Http::response('', 502));

    $job = (new SendLineMessage('測試'))->withFakeQueueInteractions();
    $job->job->attempts = 3;
    $job->handle(app(Notifier::class));

    $job->assertNotReleased();
    expect(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Failed);
});

test('a LINE 4xx is logged as failed and not retried', function () {
    fakeLine(Http::response(['message' => 'Invalid reply token'], 400));

    $job = (new SendLineMessage('測試'))->withFakeQueueInteractions();
    $job->handle(app(Notifier::class));

    $job->assertNotReleased();
    expect(NotificationLog::query()->sole())
        ->status->toBe(NotificationStatus::Failed)
        ->error->toContain('HTTP 400');
});

test('a LINE outage during a critical push never breaks the write that raised it', function () {
    fakeLine(Http::response('', 503));
    User::factory()->create();

    $insight = Insight::factory()->create(['severity' => InsightSeverity::Critical]);

    expect($insight->exists)->toBeTrue()
        ->and(NotificationLog::query()->where('channel', NotificationChannel::Line)->sole()->status)->toBe(NotificationStatus::Failed)
        ->and(NotificationLog::query()->where('channel', NotificationChannel::Database)->sole()->status)->toBe(NotificationStatus::Sent);
});

test('notify-test sends a LINE test message', function () {
    fakeLine(Http::response([], 200));

    $this->artisan('infolink:notify-test')
        ->expectsOutputToContain('LINE：已送出')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_starts_with($request['messages'][0]['text'], '✅ INFOLINK 測試通知'));
    expect(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Sent);
});

test('notify-test reports an unconfigured or failing LINE', function () {
    config(['services.line.channel_access_token' => null]);
    fakeLine(Http::response([], 200));

    $this->artisan('infolink:notify-test --channel=line')
        ->expectsOutputToContain('LINE not configured')
        ->assertFailed();

    config(['services.line.channel_access_token' => 'test-token']);
    fakeLine(Http::response('', 500));

    $this->artisan('infolink:notify-test --channel=line')
        ->expectsOutputToContain('HTTP 500')
        ->assertFailed();
});

test('notify-test can ring the bell instead', function () {
    $user = User::factory()->create();

    $this->artisan('infolink:notify-test --channel=database')->assertSuccessful();
    $this->artisan('infolink:notify-test --channel=sms')->assertExitCode(2);

    expect($user->notifications()->sole()->data['title'])->toStartWith('✅ INFOLINK 測試通知');
    Http::assertNothingSent();
});
