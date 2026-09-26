<?php

use App\Domain\Notify\MessageFormatter;
use App\Domain\Notify\QuietHours;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'app.url' => 'http://127.0.0.1:8080',
        'services.line.channel_access_token' => 'test-token',
        'services.line.user_id' => 'U-owner',
    ]);
    Http::preventStrayRequests();
    Http::fake(['api.line.me/*' => Http::response([], 200)]);
    $this->user = User::factory()->create();
    $this->body = <<<'MD'
        ## 今天要注意

        - **長照期中款** 已逾期 3 天，見 [應收](http://x/receivables)
        - 現金水位 `1,072,776`，低於警戒線

        一段說明文字，不該出現在摘要。

        ```
        - code block bullet
        ```

        | 表 | 格 |
        | --- | --- |

        ### 交付
        1. 墊腳石 APOS 2.0 驗收延後
        - [ ] 追蹤我識尾款
        - 不會被列入（第六行）
        MD;
});

test('a report with notify rings the bell and pushes a LINE summary outside quiet hours', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 08:32'));

    $report = Report::factory()->create(['title' => '每日簡報 09/28', 'body' => $this->body, 'notify' => true]);

    $expected = "📊 每日簡報 09/28\n\n■ 今天要注意\n• 長照期中款 已逾期 3 天，見 應收\n• 現金水位 1,072,776，低於警戒線\n■ 交付\n• 墊腳石 APOS 2.0 驗收延後\n\nhttp://127.0.0.1:8080/admin/reports/{$report->id}";

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['messages'][0]['text'] === $expected);

    $bell = $this->user->notifications()->sole();
    expect($bell->data['title'])->toBe('📊 每日簡報 09/28')
        ->and($bell->data['actions'][0]['url'])->toBe("http://127.0.0.1:8080/admin/reports/{$report->id}")
        ->and($report->fresh()->notified_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('report_id', $report->id)->pluck('status')->all())
        ->toBe([NotificationStatus::Sent, NotificationStatus::Sent]);

    $report->update(['body' => $this->body."\n- 更新"]);

    Http::assertSentCount(1);
});

test('a report without notify is not announced', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00'));

    Report::factory()->create(['notify' => false]);

    Http::assertNothingSent();
    expect(NotificationLog::query()->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(0);
});

test('in quiet hours the LINE summary is deferred and flushed when they end', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-27 23:10'));

    $report = Report::factory()->create(['title' => '週回顧', 'body' => $this->body, 'notify' => true]);

    Http::assertNothingSent();
    $deferred = NotificationLog::query()->where('channel', NotificationChannel::Line)->sole();
    expect($deferred->status)->toBe(NotificationStatus::Deferred)
        ->and($deferred->deliver_after->format('Y-m-d H:i'))->toBe('2026-09-28 08:00')
        ->and($deferred->payload['text'])->toStartWith('📊 週回顧')
        ->and($this->user->notifications()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-09-28 07:59'));
    $this->artisan('infolink:flush-notifications')->assertSuccessful();
    Http::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-09-28 08:03'));
    $this->artisan('infolink:flush-notifications')->assertSuccessful();
    $this->artisan('infolink:flush-notifications')->assertSuccessful();

    Http::assertSentCount(1);
    expect($deferred->fresh())
        ->status->toBe(NotificationStatus::Sent)
        ->sent_at->not->toBeNull()
        ->and(NotificationLog::query()->where('channel', NotificationChannel::Line)->count())->toBe(1)
        ->and($report->fresh()->notified_at)->not->toBeNull();
});

test('a deferred message that meets a LINE outage is retried by a queued push', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 02:00'));
    Report::factory()->create(['notify' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-09-28 08:00'));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.line.me/*' => Http::sequence()->push('down', 503)->push([], 200)]);

    $this->artisan('infolink:flush-notifications')->assertSuccessful();

    expect(NotificationLog::query()->where('channel', NotificationChannel::Line)->orderBy('id')->pluck('status')->all())
        ->toBe([NotificationStatus::Failed, NotificationStatus::Sent]);
});

test('quiet hours wrap midnight and end at the next configured end time', function (string $now, bool $quiet, string $end) {
    $quietHours = new QuietHours;
    $moment = CarbonImmutable::parse($now);

    expect($quietHours->contains($moment))->toBe($quiet)
        ->and($quietHours->endAfter($moment)->format('Y-m-d H:i'))->toBe($end);
})->with([
    ['2026-09-27 21:59', false, '2026-09-28 08:00'],
    ['2026-09-27 22:00', true, '2026-09-28 08:00'],
    ['2026-09-28 02:00', true, '2026-09-28 08:00'],
    ['2026-09-28 08:00', false, '2026-09-29 08:00'],
]);

test('the LINE summary is capped at 1000 characters and keeps the link', function () {
    $report = Report::factory()->create([
        'title' => '月結',
        'body' => collect(range(1, 8))->map(fn (int $i): string => "- 第 {$i} 點 ".str_repeat('很長的說明', 60))->implode("\n"),
        'notify' => false,
    ]);

    $text = app(MessageFormatter::class)->reportText($report);

    expect(mb_strlen($text))->toBeLessThanOrEqual(1000)
        ->and($text)->toStartWith("📊 月結\n\n• 第 1 點")
        ->and($text)->toContain("…\n\nhttp://127.0.0.1:8080/admin/reports/{$report->id}")
        ->and($text)->not->toContain('第 6 點');
});

test('a body without headings or bullets falls back to its first lines', function () {
    $lines = app(MessageFormatter::class)->summaryLines("第一行 **粗體**\n\n第二行 _斜體_ snake_case_name\n第三行", 2);

    expect($lines)->toBe(['第一行 粗體', '第二行 斜體 snake_case_name']);
});
