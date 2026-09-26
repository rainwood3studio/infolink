<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Models\Insight;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists notification attempts newest first with subject, message and error', function () {
    $insight = Insight::factory()->create(['title' => '長照期中款逾期']);
    $report = Report::factory()->create(['title' => '每日簡報 09/26']);

    $older = NotificationLog::factory()->create([
        'insight_id' => $insight->id,
        'payload' => ['text' => '🔴 嚴重：長照期中款逾期'],
        'created_at' => now()->subHour(),
    ]);
    $newer = NotificationLog::factory()->create([
        'report_id' => $report->id,
        'status' => NotificationStatus::Failed,
        'error' => 'LINE returned HTTP 500',
        'sent_at' => null,
    ]);

    Livewire::test(ListNotificationLogs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSee('注意事項：長照期中款逾期')
        ->assertSee('報告：每日簡報 09/26')
        ->assertSee('LINE returned HTTP 500')
        ->assertSee('失敗');

    $this->get('/admin/notification-logs')->assertOk();
});

it('filters by channel and status', function () {
    $line = NotificationLog::factory()->create();
    $bell = NotificationLog::factory()->create(['channel' => NotificationChannel::Database, 'payload' => ['title' => '通知']]);
    $deferred = NotificationLog::factory()->create(['status' => NotificationStatus::Deferred, 'deliver_after' => now()->addHours(8), 'sent_at' => null]);

    Livewire::test(ListNotificationLogs::class)
        ->filterTable('channel', NotificationChannel::Database->value)
        ->assertCanSeeTableRecords([$bell])
        ->assertCanNotSeeTableRecords([$line, $deferred]);

    Livewire::test(ListNotificationLogs::class)
        ->filterTable('status', NotificationStatus::Deferred->value)
        ->assertCanSeeTableRecords([$deferred])
        ->assertCanNotSeeTableRecords([$line, $bell]);
});

it('is read-only', function () {
    $log = NotificationLog::factory()->create();

    expect(NotificationLogResource::canCreate())->toBeFalse()
        ->and(NotificationLogResource::canEdit($log))->toBeFalse()
        ->and(NotificationLogResource::canDelete($log))->toBeFalse();
});
