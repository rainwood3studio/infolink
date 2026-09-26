<?php

use App\Domain\Insights\InsightService;
use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\Source;
use App\Models\Insight;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 23:30'));
    config([
        'app.url' => 'http://127.0.0.1:8080',
        'services.line.channel_access_token' => 'test-token',
        'services.line.user_id' => 'U-owner',
    ]);
    Http::preventStrayRequests();
    Http::fake(['api.line.me/*' => Http::response([], 200)]);
    $this->user = User::factory()->create();
    $this->raise = fn (array $overrides = []) => app(InsightService::class)->raise([
        'fingerprint' => 'receivable-overdue:長照-期中款',
        'severity' => InsightSeverity::Critical,
        'category' => Category::Finance,
        'title' => '長照期中款 47.25 萬已逾期 3 天',
        'body' => "應收 **472,500**，原訂 9/23 入帳。\n\n- 請聯絡承辦窗口",
        ...$overrides,
    ], Source::System);
});

test('a critical insight pushes LINE and the bell immediately, even in quiet hours', function () {
    $insight = ($this->raise)();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.line.me/v2/bot/message/push'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['to'] === 'U-owner'
        && $request['messages'][0]['type'] === 'text'
        && $request['messages'][0]['text'] === "🔴 嚴重：長照期中款 47.25 萬已逾期 3 天\n\n應收 472,500，原訂 9/23 入帳。\n請聯絡承辦窗口\n\nhttp://127.0.0.1:8080/admin/insights/{$insight->id}");

    $bell = $this->user->notifications()->sole();
    expect($bell->data['title'])->toBe('🔴 嚴重：長照期中款 47.25 萬已逾期 3 天')
        ->and($bell->data['status'])->toBe('danger')
        ->and($bell->data['actions'][0]['url'])->toBe("http://127.0.0.1:8080/admin/insights/{$insight->id}")
        ->and($insight->fresh()->notified_at)->not->toBeNull()
        ->and(NotificationLog::query()->orderBy('id')->get()->map(fn (NotificationLog $log): array => [$log->channel, $log->status, $log->insight_id])->all())
        ->toBe([
            [NotificationChannel::Database, NotificationStatus::Sent, $insight->id],
            [NotificationChannel::Line, NotificationStatus::Sent, $insight->id],
        ]);
});

test('a warning insight only rings the bell', function () {
    $insight = ($this->raise)(['severity' => InsightSeverity::Warning]);

    Http::assertNothingSent();
    expect($this->user->notifications()->sole()->data['title'])->toBe('🟡 注意：長照期中款 47.25 萬已逾期 3 天')
        ->and($insight->fresh()->notified_at)->not->toBeNull()
        ->and(NotificationLog::query()->pluck('channel')->all())->toBe([NotificationChannel::Database]);
});

test('an info insight is not pushed anywhere', function () {
    $insight = ($this->raise)(['severity' => InsightSeverity::Info]);

    Http::assertNothingSent();
    expect($this->user->notifications()->count())->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and($insight->fresh()->notified_at)->toBeNull();
});

test('resolved insights and repeated raises are not pushed again', function () {
    Insight::factory()->create(['severity' => InsightSeverity::Critical, 'status' => InsightStatus::Resolved]);
    ($this->raise)();
    ($this->raise)(['title' => '長照期中款 47.25 萬已逾期 4 天']);

    Http::assertSentCount(1);
    expect(NotificationLog::query()->count())->toBe(2);
});

test('the same fingerprint is pushed at most once per dedupe window', function () {
    $first = ($this->raise)();
    app(InsightService::class)->resolve($first);

    $this->travel(23)->hours();
    $second = ($this->raise)();

    Http::assertSentCount(1);
    expect($second->id)->not->toBe($first->id)
        ->and($second->fresh()->notified_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('insight_id', $second->id)->sole())
        ->channel->toBe(NotificationChannel::Line)
        ->status->toBe(NotificationStatus::Skipped)
        ->error->toBe('同一 fingerprint 在 24 小時內已推播過')
        ->and($this->user->notifications()->count())->toBe(1);

    app(InsightService::class)->resolve($second);
    $this->travel(2)->hours();
    ($this->raise)();

    Http::assertSentCount(2);
});

test('escalating to critical pushes again even within the dedupe window', function () {
    $insight = ($this->raise)(['severity' => InsightSeverity::Warning]);
    Http::assertNothingSent();

    $this->travel(1)->hour();
    $escalated = ($this->raise)(['severity' => InsightSeverity::Critical]);

    expect($escalated->id)->toBe($insight->id);
    Http::assertSentCount(1);
    expect($this->user->notifications()->count())->toBe(2);
});

test('notification is dispatched only after the surrounding transaction commits', function () {
    DB::transaction(function () {
        $insight = ($this->raise)();

        Http::assertNothingSent();
        expect(NotificationLog::query()->count())->toBe(0)
            ->and($insight->fresh()->notified_at)->toBeNull();
    });

    Http::assertSentCount(1);
    expect(NotificationLog::query()->count())->toBe(2);
});

test('nothing is sent when the transaction rolls back', function () {
    try {
        DB::transaction(function () {
            ($this->raise)();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        //
    }

    Http::assertNothingSent();
    expect(NotificationLog::query()->count())->toBe(0);
});
