<?php

use App\Domain\Insights\InsightService;
use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\Source;
use App\Models\AlertRule;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.line.channel_access_token' => 'test-token',
        'services.line.user_id' => 'U-owner',
    ]);
    Http::preventStrayRequests();
    Http::fake(['api.line.me/*' => Http::response([], 200)]);
    User::factory()->create();
    AlertRule::factory()->create(['key' => 'receivable-overdue', 'notify_channels' => ['database', 'line']]);
    AlertRule::factory()->create(['key' => 'delivery-offflow', 'notify_channels' => null]);
    $this->raiseWarning = fn (string $rule) => app(InsightService::class)->raise([
        'fingerprint' => "{$rule}:1",
        'severity' => InsightSeverity::Warning,
        'category' => Category::Finance,
        'title' => '長照期中款 47.25 萬已逾期 5 天',
        'evidence' => ['alert_rule' => $rule],
    ], Source::System);
});

test('a warning from a rule that opts into LINE is pushed to the phone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00'));

    ($this->raiseWarning)('receivable-overdue');

    Http::assertSentCount(1);
    expect(NotificationLog::query()->where('channel', NotificationChannel::Line)->sole()->status)->toBe(NotificationStatus::Sent);
});

test('a warning from a rule without LINE only rings the bell', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00'));

    ($this->raiseWarning)('delivery-offflow');

    Http::assertNothingSent();
    expect(NotificationLog::query()->where('channel', NotificationChannel::Line)->exists())->toBeFalse();
});

test('an opted-in warning waits for the end of quiet hours', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 23:00'));

    ($this->raiseWarning)('receivable-overdue');

    Http::assertNothingSent();
    $log = NotificationLog::query()->where('channel', NotificationChannel::Line)->sole();
    expect($log->status)->toBe(NotificationStatus::Deferred)
        ->and($log->deliver_after->format('Y-m-d H:i'))->toBe('2026-09-29 08:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-29 08:05'));
    $this->artisan('infolink:flush-notifications')->assertSuccessful();

    Http::assertSentCount(1);
    expect($log->fresh()->status)->toBe(NotificationStatus::Sent);
});
