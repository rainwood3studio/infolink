<?php

use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Filament\Widgets\AttentionListWidget;
use App\Models\ActionItem;
use App\Models\Insight;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it lists open critical insights and overdue action items', function () {
    Insight::factory()->create(['title' => '長照期中款逾期', 'severity' => InsightSeverity::Critical]);
    Insight::factory()->create(['title' => '只是資訊', 'severity' => InsightSeverity::Info]);
    ActionItem::factory()->create(['title' => '寄出我識尾款發票', 'due_on' => today()->subDays(2)]);
    ActionItem::factory()->create(['title' => '下個月的事', 'due_on' => today()->addMonth()]);

    Livewire::test(AttentionListWidget::class)
        ->assertSee('長照期中款逾期')
        ->assertSee('寄出我識尾款發票')
        ->assertSee('逾期 2 天')
        ->assertDontSee('只是資訊')
        ->assertDontSee('下個月的事');
});

test('resolving an insight removes it from the list', function () {
    $insight = Insight::factory()->create(['title' => '現金低點', 'severity' => InsightSeverity::Warning]);

    Livewire::test(AttentionListWidget::class)
        ->call('resolveInsight', $insight->id)
        ->assertDontSee('現金低點');

    expect($insight->fresh()->status)->toBe(InsightStatus::Resolved);
});

test('an action item can be created from an insight', function () {
    $insight = Insight::factory()->create(['severity' => InsightSeverity::Critical]);

    Livewire::test(AttentionListWidget::class)->call('createActionItemFromInsight', $insight->id);

    expect($insight->actionItems()->count())->toBe(1)
        ->and($insight->fresh()->status)->toBe(InsightStatus::Acknowledged);
});

test('completing an action item marks it done', function () {
    $actionItem = ActionItem::factory()->create(['due_on' => today()]);

    Livewire::test(AttentionListWidget::class)->call('completeActionItem', $actionItem->id);

    expect($actionItem->fresh()->status)->toBe(ActionItemStatus::Done);
});

test('it shows an empty state when nothing needs attention', function () {
    Livewire::test(AttentionListWidget::class)->assertSee('目前沒有需要處理的事');
});
