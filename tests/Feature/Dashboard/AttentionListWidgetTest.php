<?php

use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Filament\Widgets\AttentionListWidget;
use App\Models\ActionItem;
use App\Models\Developer;
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

test('delegated items sit in their own block, away from my list', function () {
    ActionItem::factory()->create(['title' => '我自己的事', 'due_on' => today()->subDay(), 'owner' => 'Kenneth']);
    ActionItem::factory()->create(['title' => '裕樺逾期的事', 'due_on' => today()->subDays(3), 'owner' => '裕樺']);
    ActionItem::factory()->create(['title' => '文豪下週的事', 'due_on' => today()->addWeek(), 'owner' => '文豪']);

    Livewire::test(AttentionListWidget::class)
        ->assertSeeInOrder(['今天要處理', '1 項', '我自己的事', '已交辦', '2 項，1 項逾期', '逾期 3 天', '裕樺', '裕樺逾期的事', '收回', '未到期', '文豪下週的事']);
});

test('the delegated block is hidden when nothing is delegated', function () {
    ActionItem::factory()->create(['due_on' => today(), 'owner' => 'Kenneth']);

    Livewire::test(AttentionListWidget::class)->assertDontSee('已交辦')->assertDontSee('收回');
});

test('an action item can be postponed to the next weekday, next Monday or a week later', function (string $choice, string $dueOn) {
    $this->travelTo('2026-10-02 09:00:00');
    $actionItem = ActionItem::factory()->create(['title' => '可以晚點做的事', 'due_on' => '2026-09-28']);

    Livewire::test(AttentionListWidget::class)
        ->assertSee('可以晚點做的事')
        ->call('postponeActionItem', $actionItem->id, $choice)
        ->assertNotified()
        ->assertDontSee('可以晚點做的事');

    expect($actionItem->fresh()->due_on->toDateString())->toBe($dueOn);
})->with([
    'tomorrow skips the weekend' => ['tomorrow', '2026-10-05'],
    'next monday' => ['next_monday', '2026-10-05'],
    'in a week' => ['in_a_week', '2026-10-09'],
]);

test('an action item can be delegated to an active developer and taken back', function () {
    Developer::factory()->create(['name' => '裕樺']);
    Developer::factory()->create(['name' => 'Kenneth']);
    $actionItem = ActionItem::factory()->create(['title' => '整理驗證中議題', 'due_on' => today()]);

    Livewire::test(AttentionListWidget::class)
        ->assertSee('交辦')
        ->call('delegateActionItem', $actionItem->id, '裕樺')
        ->assertNotified('已交辦給 裕樺');

    expect($actionItem->fresh()->owner)->toBe('裕樺');

    Livewire::test(AttentionListWidget::class)
        ->assertSeeInOrder(['目前沒有需要處理的事', '已交辦', '裕樺', '整理驗證中議題'])
        ->call('takeBackActionItem', $actionItem->id)
        ->assertDontSee('已交辦');

    expect($actionItem->fresh()->owner)->toBe('Kenneth');
});

test('an action item cannot be delegated to someone who is not an active developer', function () {
    Developer::factory()->create(['name' => '離職的人', 'is_active' => false]);
    $actionItem = ActionItem::factory()->create(['due_on' => today()]);

    Livewire::test(AttentionListWidget::class)
        ->call('delegateActionItem', $actionItem->id, '離職的人')
        ->assertStatus(422);

    expect($actionItem->fresh()->owner)->toBeNull();
});

test('dropping an action item removes it from either block', function (?string $owner) {
    $actionItem = ActionItem::factory()->create(['title' => '不做了的事', 'due_on' => today()->subDay(), 'owner' => $owner]);

    Livewire::test(AttentionListWidget::class)
        ->call('dropActionItem', $actionItem->id)
        ->assertDontSee('不做了的事');

    expect($actionItem->fresh()->status)->toBe(ActionItemStatus::Dropped);
})->with(['mine' => [null], 'delegated' => ['裕樺']]);
