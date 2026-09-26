<?php

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Filament\Widgets\DataFreshnessWidget;
use App\Models\BankTransaction;
use App\Models\SyncRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it shows placeholders before anything has synced', function () {
    Livewire::test(DataFreshnessWidget::class)
        ->assertSee('資料新鮮度')
        ->assertSee('尚未同步')
        ->assertSee('尚未匯入')
        ->assertSee('今日簡報')
        ->assertSee('尚未啟用');
});

test('it shows how long ago the last successful issue sync finished', function () {
    SyncRun::factory()->create(['started_at' => now()->subMinutes(13), 'finished_at' => now()->subMinutes(12)]);
    SyncRun::factory()->create(['job' => SyncJob::VaultTasks, 'status' => SyncStatus::Failed, 'started_at' => now()]);
    BankTransaction::factory()->create(['txn_date' => '2026-09-22']);

    Livewire::test(DataFreshnessWidget::class)
        ->assertSee(now()->subMinutes(12)->diffForHumans())
        ->assertDontSee('同步失敗')
        ->assertSee('至 09/22');
});

test('a failure after a success shows the failure and when it last succeeded', function () {
    SyncRun::factory()->create(['started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3)]);
    SyncRun::factory()->create(['status' => SyncStatus::Failed, 'started_at' => now()->subMinutes(5), 'finished_at' => null, 'error' => 'timeout']);

    Livewire::test(DataFreshnessWidget::class)
        ->assertSee('同步失敗 '.now()->subMinutes(5)->diffForHumans())
        ->assertSee('上次成功 '.now()->subHours(3)->diffForHumans());
});

test('a failure without any earlier success says it never succeeded', function () {
    SyncRun::factory()->create(['status' => SyncStatus::Failed, 'started_at' => now()->subMinutes(5)]);

    Livewire::test(DataFreshnessWidget::class)
        ->assertSee('同步失敗')
        ->assertSee('從未成功');
});

test('relative times render in chinese', function () {
    expect(now()->subMinutes(12)->diffForHumans())->toBe('12 分鐘前');
});
