<?php

use App\Enums\DealEventType;
use App\Enums\DealStage;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\GetBriefing;
use App\Mcp\Tools\ListDeals;
use App\Mcp\Tools\LogDealEvent;
use App\Mcp\Tools\UpsertDeal;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealEvent;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');
    $this->customer = Customer::factory()->create(['short_name' => '長照']);
});

describe('upsert_deal', function () {
    test('creates a prospect deal', function () {
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(UpsertDeal::class, [
                'prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞', 'stage' => 'proposal',
                'amount_untaxed' => 800_000, 'probability' => 30, 'next_action' => '追蹤提案', 'next_action_on' => '2026-10-01',
            ])
            ->assertOk()
            ->assertSee(['"result":"created"', '"party":"多羅滿賞鯨"', '"weighted_amount":240000', '"needs_next_action":false', '"source":"claude"']);

        expect(Deal::sole())->stage->toBe(DealStage::Proposal)->actor->toBe('claude-cli');
    });

    test('is idempotent by party + title and logs stage changes', function () {
        $arguments = ['customer' => '長照', 'title' => '二期', 'stage' => 'proposal', 'amount_untaxed' => 500_000];

        InfolinkServer::actingAs(mcpUser(['write']))->tool(UpsertDeal::class, $arguments)->assertOk();
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(UpsertDeal::class, [...$arguments, 'stage' => 'won', 'stage_note' => '簽約完成', 'stage_changed_on' => '2026-09-25'])
            ->assertOk()
            ->assertSee(['"result":"updated"', '"stage_changed":true', '"stage":"won"', '"probability":100', '"closed_at":"2026-09-25"', '"type":"stage_change"', '"from_stage":"proposal"', '"to_stage":"won"']);

        expect(Deal::count())->toBe(1)
            ->and(DealEvent::sole()->content)->toBe('簽約完成');
    });

    test('updates by id and by external key', function () {
        $deal = Deal::factory()->create(['external_key' => 'vault:x', 'source' => Source::Vault, 'probability' => 20]);

        InfolinkServer::actingAs(mcpUser(['write']))->tool(UpsertDeal::class, ['id' => $deal->id, 'probability' => 40])->assertOk()->assertSee('"stage_changed":false');
        InfolinkServer::actingAs(mcpUser(['write']))->tool(UpsertDeal::class, ['external_key' => 'vault:x', 'title' => '改名', 'prospect_name' => $deal->prospect_name])->assertOk();

        expect(Deal::sole())->probability->toBe(40)->title->toBe('改名')->source->toBe(Source::Vault);
    });

    test('explains unknown customers, missing parties and bad ids', function (array $arguments, string $message) {
        Customer::factory()->create(['short_name' => '我識']);

        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(UpsertDeal::class, $arguments)
            ->assertHasErrors([$message]);

        expect(Deal::count())->toBe(0);
    })->with([
        'unknown customer' => [['customer' => '長照中心', 'title' => 'T'], 'Did you mean: 長照'],
        'no party' => [['title' => 'T'], 'needs `customer`'],
        'bad id' => [['id' => 999], 'No deal with id 999'],
        'bad probability' => [['prospect_name' => 'A', 'title' => 'T', 'probability' => 150], 'percentage 0–100'],
    ]);

    test('requires the write ability', function () {
        InfolinkServer::actingAs(mcpUser(['read']))
            ->tool(UpsertDeal::class, ['prospect_name' => 'A', 'title' => 'T'])
            ->assertHasErrors();

        expect(Deal::count())->toBe(0);
    });
});

describe('log_deal_event', function () {
    test('logs an event idempotently and can update the next action', function () {
        $deal = Deal::factory()->create(['next_action_on' => null]);
        $arguments = ['deal_id' => $deal->id, 'type' => 'meeting', 'content' => '拜訪窗口', 'occurred_on' => '2026-09-24', 'external_key' => 'meet-0924'];

        InfolinkServer::actingAs(mcpUser(['write']))->tool(LogDealEvent::class, $arguments)->assertOk()->assertSee('"result":"created"');
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(LogDealEvent::class, [...$arguments, 'content' => '拜訪窗口，對方要求報價', 'next_action' => '寄報價', 'next_action_on' => '2026-09-30'])
            ->assertOk()
            ->assertSee(['"result":"updated"', '"next_action":"寄報價"', '"needs_next_action":false', '"days_since_last_event":2']);

        expect(DealEvent::sole())
            ->type->toBe(DealEventType::Meeting)
            ->content->toBe('拜訪窗口，對方要求報價')
            ->source->toBe(Source::Claude);
    });

    test('rejects stage changes and unknown deals', function (array $arguments, string $message) {
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(LogDealEvent::class, $arguments)
            ->assertHasErrors([$message]);
    })->with([
        'stage change' => [['deal_id' => 1, 'type' => 'stage_change', 'content' => 'x'], 'upsert_deal'],
        'unknown deal' => [['deal_id' => 999, 'type' => 'note', 'content' => 'x'], 'No deal matches id 999'],
        'unknown deal key' => [['deal_external_key' => 'nope', 'type' => 'note', 'content' => 'x'], 'external_key [nope]'],
    ]);
});

describe('list_deals', function () {
    beforeEach(function () {
        $this->open = Deal::factory()->for($this->customer)->create([
            'title' => '二期', 'stage' => DealStage::Negotiation, 'amount_untaxed' => 1_000_000, 'probability' => 50, 'next_action_on' => '2026-10-01',
        ]);
        $this->stale = Deal::factory()->create(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨', 'stage' => DealStage::Lead, 'next_action_on' => null]);
        Deal::factory()->create(['prospect_name' => '已成交', 'stage' => DealStage::Won, 'closed_at' => '2026-09-01']);

        foreach (['2026-09-01', '2026-09-10', '2026-09-20', '2026-09-22'] as $index => $date) {
            DealEvent::factory()->for($this->open)->create(['occurred_on' => $date, 'content' => "事件 {$index}"]);
        }
    });

    test('lists open deals with weighted amounts, events and the pipeline', function () {
        InfolinkServer::actingAs(mcpUser(['read']))
            ->tool(ListDeals::class)
            ->assertOk()
            ->assertSee([
                '"count":2',
                '"weighted_total":'.(500_000 + $this->stale->weighted_amount),
                '"no_next_action_count":1',
                '"party":"長照"', '"weighted_amount":500000', '"days_since_last_event":4', '"last_event_on":"2026-09-22"',
                '事件 3', '事件 1',
                '"party":"多羅滿賞鯨"', '"needs_next_action":true', '"days_since_last_event":null',
            ])
            ->assertDontSee(['已成交', '事件 0']);
    });

    test('filters', function (array $arguments, array $see, array $dontSee) {
        InfolinkServer::actingAs(mcpUser(['read']))
            ->tool(ListDeals::class, $arguments)
            ->assertOk()
            ->assertSee($see)
            ->assertDontSee($dontSee);
    })->with([
        'no next action' => [['no_next_action' => true], ['多羅滿賞鯨'], ['"title":"二期"']],
        'stage' => [['stage' => 'won'], ['已成交'], ['多羅滿賞鯨']],
        'all' => [['open_only' => false], ['已成交', '多羅滿賞鯨', '"title":"二期"'], []],
        'party' => [['party' => '長照'], ['"title":"二期"'], ['多羅滿賞鯨']],
    ]);
});

test('get_briefing includes the sales pipeline', function () {
    Deal::factory()->create(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨', 'stage' => DealStage::Proposal, 'amount_untaxed' => 800_000, 'probability' => 30, 'next_action_on' => null]);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetBriefing::class)
        ->assertOk()
        ->assertSee([
            '"sales":{"open_count":1,"amount_total":800000,"weighted_total":240000',
            '"proposal":{"label":"提案","count":1,"amount":800000,"weighted":240000}',
            '"no_next_action":[{"id":',
            '"party":"多羅滿賞鯨","title":"賞鯨","stage":"proposal","weighted_amount":240000,"next_action":"追蹤提案回覆","next_action_on":null}',
        ]);
});
