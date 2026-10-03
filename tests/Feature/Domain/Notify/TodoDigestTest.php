<?php

use App\Domain\Work\TodoDigest;
use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\ActionItem;
use App\Models\NotificationLog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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
    $this->travelTo('2026-10-05 08:10:00');
});

test('the digest names my three most pressing items, then what is left and what is delegated and overdue', function () {
    ActionItem::factory()->create(['title' => '回覆多羅滿報價', 'due_on' => '2026-10-05', 'priority' => ActionItemPriority::P1]);
    ActionItem::factory()->create(['title' => '寄出我識尾款發票', 'due_on' => '2026-10-02', 'priority' => ActionItemPriority::P2, 'owner' => 'Kenneth']);
    ActionItem::factory()->create(['title' => '對帳', 'due_on' => '2026-10-02', 'priority' => ActionItemPriority::P3]);
    ActionItem::factory()->create(['title' => '開長照期中款發票', 'due_on' => '2026-09-23', 'priority' => ActionItemPriority::P3, 'status' => ActionItemStatus::Doing]);
    ActionItem::factory()->create(['title' => '明天的事', 'due_on' => '2026-10-06']);
    ActionItem::factory()->create(['title' => '做完的事', 'due_on' => '2026-10-01', 'status' => ActionItemStatus::Done]);

    ActionItem::factory()->count(2)->create(['due_on' => '2026-10-01', 'owner' => '裕樺']);
    ActionItem::factory()->create(['due_on' => '2026-09-30', 'owner' => '文豪']);
    ActionItem::factory()->create(['due_on' => '2026-10-05', 'owner' => '文豪']);

    expect(app(TodoDigest::class)->message())->toBe(<<<'TEXT'
        今天先做這三件
        • 開長照期中款發票（逾期 12 天）
        • 寄出我識尾款發票（逾期 3 天）
        • 對帳（逾期 3 天）
        另有 1 件到期／逾期；已交辦逾期 3 件（裕樺 2、文豪 1）
        http://127.0.0.1:8080/admin/action-items
        TEXT);
});

test('the digest adapts to fewer than three items and leaves out an empty summary line', function () {
    ActionItem::factory()->create(['title' => '回覆多羅滿報價', 'due_on' => '2026-10-05']);

    expect(app(TodoDigest::class)->message())->toBe("今天先做這一件\n• 回覆多羅滿報價（今天到期）\nhttp://127.0.0.1:8080/admin/action-items");
});

test('the digest still reports overdue delegated items when nothing of mine is due', function () {
    ActionItem::factory()->create(['due_on' => '2026-10-01', 'owner' => '裕樺']);

    expect(app(TodoDigest::class)->message())->toBe("今天沒有到期的待辦\n已交辦逾期 1 件（裕樺 1）\nhttp://127.0.0.1:8080/admin/action-items");
});

test('there is no digest when nothing is due', function () {
    ActionItem::factory()->create(['due_on' => '2026-10-06']);
    ActionItem::factory()->create(['due_on' => null]);
    ActionItem::factory()->create(['due_on' => '2026-10-05', 'owner' => '裕樺']);

    expect(app(TodoDigest::class)->message())->toBeNull();
});

test('notify-todos pushes the digest to LINE and logs it', function () {
    ActionItem::factory()->create(['title' => '回覆多羅滿報價', 'due_on' => '2026-10-05']);

    $this->artisan('infolink:notify-todos')->expectsOutputToContain('LINE：已送出')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['to'] === 'U-owner'
        && str_starts_with($request['messages'][0]['text'], "今天先做這一件\n• 回覆多羅滿報價（今天到期）"));

    expect(NotificationLog::query()->sole())
        ->channel->toBe(NotificationChannel::Line)
        ->status->toBe(NotificationStatus::Sent);
});

test('notify-todos sends nothing when nothing is due', function () {
    ActionItem::factory()->create(['due_on' => '2026-10-06']);

    $this->artisan('infolink:notify-todos')->expectsOutputToContain('不發送')->assertSuccessful();

    Http::assertNothingSent();
    expect(NotificationLog::query()->count())->toBe(0);
});

test('notify-todos --dry-run prints the message without sending or logging', function () {
    ActionItem::factory()->create(['title' => '回覆多羅滿報價', 'due_on' => '2026-10-02']);

    $this->artisan('infolink:notify-todos --dry-run')
        ->expectsOutputToContain('• 回覆多羅滿報價（逾期 3 天）')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(NotificationLog::query()->count())->toBe(0);
});

test('notify-todos defers the digest during quiet hours', function () {
    $this->travelTo('2026-10-05 07:30:00');
    ActionItem::factory()->create(['due_on' => '2026-10-05']);

    $this->artisan('infolink:notify-todos')->assertSuccessful();

    Http::assertNothingSent();
    expect(NotificationLog::query()->sole())
        ->status->toBe(NotificationStatus::Deferred)
        ->deliver_after->format('Y-m-d H:i')->toBe('2026-10-05 08:00');
});

test('notify-todos fails when LINE is not configured', function () {
    config(['services.line.channel_access_token' => null]);
    ActionItem::factory()->create(['due_on' => '2026-10-05']);

    $this->artisan('infolink:notify-todos')->expectsOutputToContain('LINE not configured')->assertFailed();

    Http::assertNothingSent();
});

test('notify-todos is scheduled on weekdays at 08:10 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'infolink:notify-todos'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 8 * * 1-5')
        ->and($event->withoutOverlapping)->toBeTrue();
});
