<?php

use App\Filament\Pages\ServerDisks;
use App\Filament\Widgets\ServerDiskStatsWidget;
use App\Filament\Widgets\ServerDiskTrendChart;
use App\Models\Server;
use App\Models\ServerDiskSample;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $collectedAt = now()->startOfSecond();
    $full = Server::factory()->create(['name' => 'scm', 'last_collected_at' => $collectedAt]);
    $roomy = Server::factory()->create(['name' => 'excalibur-web', 'last_collected_at' => $collectedAt]);
    Server::factory()->create(['name' => 'crawler', 'last_collected_at' => null, 'last_error' => 'SSM 狀態 ConnectionLost']);

    ServerDiskSample::factory()->for($full)->create(['collected_at' => $collectedAt, 'used_percent' => 91.5]);
    ServerDiskSample::factory()->for($full)->create(['collected_at' => $collectedAt->copy()->subDay(), 'used_percent' => 90]);
    ServerDiskSample::factory()->for($roomy)->create(['collected_at' => $collectedAt, 'used_percent' => 13]);
});

it('lists the newest filesystems fullest first', function () {
    $this->get(ServerDisks::getUrl())->assertOk();

    Livewire::test(ServerDisks::class)
        ->assertSeeInOrder(['scm', '91.5%', 'excalibur-web', '13.0%'])
        ->assertDontSee('90.0%');
});

it('summarises the fullest disk and the unreachable servers', function () {
    Livewire::test(ServerDiskStatsWidget::class)
        ->assertSeeInOrder(['機器', '3'])
        ->assertSeeInOrder(['最滿', '91.5%', 'scm /'])
        ->assertSeeInOrder(['≥ 80%', '1', '其中 1 個 ≥ 90%'])
        ->assertSeeInOrder(['無法取得', '1', 'crawler']);

    Livewire::test(ServerDiskTrendChart::class)->assertOk();
});
