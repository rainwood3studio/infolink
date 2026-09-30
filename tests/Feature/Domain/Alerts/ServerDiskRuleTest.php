<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerDiskSample;
use Database\Seeders\AlertRuleSeeder;

beforeEach(function () {
    $this->seed(AlertRuleSeeder::class);
    $this->server = Server::factory()->create(['name' => 'scm', 'instance_id' => 'i-scm', 'last_collected_at' => now()->startOfSecond()]);
    $this->disk = fn (float $percent, string $mount = '/') => ServerDiskSample::factory()->for($this->server)->create([
        'collected_at' => $this->server->last_collected_at,
        'mount' => $mount,
        'size_bytes' => 100 * 1024 ** 3,
        'used_bytes' => (int) ($percent * 1024 ** 3),
        'available_bytes' => (int) ((100 - $percent) * 1024 ** 3),
        'used_percent' => $percent,
    ]);
});

test('raises one insight per filesystem above the threshold, critical above the critical threshold', function () {
    ($this->disk)(83);
    ($this->disk)(95, '/data');
    ($this->disk)(40, '/boot');

    app(RuleEvaluator::class)->evaluate('server-disk');

    expect(Insight::orderBy('fingerprint')->get()->map->only('fingerprint', 'severity', 'title')->all())->toBe([
        ['fingerprint' => 'disk-usage:i-scm:/', 'severity' => InsightSeverity::Warning, 'title' => 'scm / 已用 83%（剩 17.0 GB）'],
        ['fingerprint' => 'disk-usage:i-scm:/data', 'severity' => InsightSeverity::Critical, 'title' => 'scm /data 已用 95%（剩 5.0 GB）'],
    ]);
});

test('resolves once space is freed', function () {
    $sample = ($this->disk)(85);
    app(RuleEvaluator::class)->evaluate('server-disk');

    $sample->update(['used_percent' => 50]);
    app(RuleEvaluator::class)->evaluate('server-disk');

    expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
});
