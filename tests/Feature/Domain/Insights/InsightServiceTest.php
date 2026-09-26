<?php

use App\Domain\Insights\InsightService;
use App\Enums\ActionItemPriority;
use App\Enums\Category;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\Source;
use App\Models\Insight;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->service = app(InsightService::class);
    $this->raiseOverdue = fn (array $overrides = []) => $this->service->raise([
        'fingerprint' => 'receivable-overdue:長照-期中款',
        'kind' => InsightKind::Risk,
        'severity' => InsightSeverity::Warning,
        'category' => Category::Finance,
        'title' => '長照期中款 47.25 萬逾期 3 天',
        'evidence' => ['receivable_id' => 42],
        ...$overrides,
    ], Source::Claude);
});

test('raising a new fingerprint creates an open insight', function () {
    $insight = ($this->raiseOverdue)();

    expect($insight->status)->toBe(InsightStatus::Open)
        ->and($insight->source)->toBe(Source::Claude)
        ->and($insight->first_seen_at->eq(now()))->toBeTrue()
        ->and($insight->last_seen_at->eq(now()))->toBeTrue();
});

test('raising an unresolved fingerprint again updates it instead of duplicating', function () {
    $first = ($this->raiseOverdue)();
    $this->service->acknowledge($first);

    $this->travel(2)->days();
    $second = ($this->raiseOverdue)(['title' => '長照期中款 47.25 萬逾期 5 天', 'evidence' => ['receivable_id' => 42, 'days' => 5]]);

    expect(Insight::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->title)->toBe('長照期中款 47.25 萬逾期 5 天')
        ->and($second->evidence)->toBe(['receivable_id' => 42, 'days' => 5])
        ->and($second->status)->toBe(InsightStatus::Acknowledged)
        ->and($second->first_seen_at->toDateString())->toBe('2026-09-24')
        ->and($second->last_seen_at->toDateString())->toBe('2026-09-26');
});

test('an escalated severity reopens an acknowledged insight and clears notified_at', function () {
    $insight = ($this->raiseOverdue)();
    $this->service->acknowledge($insight);
    $insight->update(['notified_at' => now()]);

    $escalated = ($this->raiseOverdue)(['severity' => InsightSeverity::Critical]);

    expect($escalated->severity)->toBe(InsightSeverity::Critical)
        ->and($escalated->status)->toBe(InsightStatus::Open)
        ->and($escalated->notified_at)->toBeNull();
});

test('a lowered severity keeps the acknowledged status', function () {
    $insight = ($this->raiseOverdue)(['severity' => InsightSeverity::Critical]);
    $this->service->acknowledge($insight);

    $lowered = ($this->raiseOverdue)(['severity' => 'warning']);

    expect($lowered->severity)->toBe(InsightSeverity::Warning)
        ->and($lowered->status)->toBe(InsightStatus::Acknowledged);
});

test('a resolved or dismissed fingerprint is raised as a new insight', function (string $close) {
    $first = ($this->raiseOverdue)();
    $this->service->{$close}($first);

    $second = ($this->raiseOverdue)();

    expect(Insight::count())->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(InsightStatus::Open);
})->with(['resolve', 'dismiss']);

test('raising without a fingerprint throws', function () {
    $this->service->raise(['title' => 'x', 'category' => Category::Finance]);
})->throws(InvalidArgumentException::class);

test('resolving records the time and appends the reason to notes', function () {
    $insight = ($this->raiseOverdue)(['notes' => '客戶說月底付']);

    $this->service->resolve($insight, '10/01 已入帳');

    expect($insight->fresh())
        ->status->toBe(InsightStatus::Resolved)
        ->resolved_at->not->toBeNull()
        ->notes->toBe("客戶說月底付\n10/01 已入帳");
});

test('resolveByFingerprint only resolves unresolved insights with that fingerprint', function () {
    Insight::factory()->count(2)->create(['fingerprint' => 'cash-runway']);
    Insight::factory()->create(['fingerprint' => 'cash-runway', 'status' => InsightStatus::Dismissed]);
    $other = Insight::factory()->create(['fingerprint' => 'cash-low:2026-11']);

    expect($this->service->resolveByFingerprint('cash-runway'))->toBe(2)
        ->and(Insight::where('fingerprint', 'cash-runway')->where('status', InsightStatus::Resolved)->count())->toBe(2)
        ->and($other->fresh()->status)->toBe(InsightStatus::Open);
});

test('expireStale resolves unresolved insights past their expiry', function () {
    $expired = Insight::factory()->create(['expires_at' => now()->subMinute()]);
    $expiredAcknowledged = Insight::factory()->create(['expires_at' => now()->subDay(), 'status' => InsightStatus::Acknowledged]);
    $future = Insight::factory()->create(['expires_at' => now()->addDay()]);
    $noExpiry = Insight::factory()->create();

    expect($this->service->expireStale())->toBe(2)
        ->and($expired->fresh()->status)->toBe(InsightStatus::Resolved)
        ->and($expiredAcknowledged->fresh()->status)->toBe(InsightStatus::Resolved)
        ->and($future->fresh()->status)->toBe(InsightStatus::Open)
        ->and($noExpiry->fresh()->status)->toBe(InsightStatus::Open);
});

test('an action item created from an insight is linked to it', function () {
    $insight = ($this->raiseOverdue)(['severity' => InsightSeverity::Critical]);

    $actionItem = $this->service->createActionItem($insight, ['due_on' => '2026-09-30']);

    expect($actionItem->related->is($insight))->toBeTrue()
        ->and($actionItem->title)->toBe($insight->title)
        ->and($actionItem->priority)->toBe(ActionItemPriority::P1)
        ->and($insight->actionItems)->toHaveCount(1);
});
