<?php

use App\Domain\Sales\DealService;
use App\Enums\DealEventType;
use App\Enums\DealStage;
use App\Enums\Source;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealEvent;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00'));
    $this->service = app(DealService::class);
});

describe('upsert', function () {
    test('creates a deal for a prospect, defaulting to lead', function () {
        $deal = $this->service->upsert(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞', 'amount_untaxed' => 800_000, 'probability' => 30], Source::Claude);

        expect($deal->wasRecentlyCreated)->toBeTrue()
            ->and($deal->stage)->toBe(DealStage::Lead)
            ->and($deal->source)->toBe(Source::Claude)
            ->and($deal->weighted_amount)->toBe(240_000)
            ->and(DealEvent::count())->toBe(0);
    });

    test('is idempotent by external key', function () {
        $this->service->upsert(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞'], Source::Vault, 'vault:whale');
        $deal = $this->service->upsert(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞 v2', 'probability' => 40], Source::Claude, 'vault:whale');

        expect(Deal::count())->toBe(1)
            ->and($deal->title)->toBe('賞鯨訂位中樞 v2')
            ->and($deal->source)->toBe(Source::Vault);
    });

    test('matches by customer + title, or prospect + title, without a key', function () {
        $customer = Customer::factory()->create(['short_name' => '長照']);

        $this->service->upsert(['customer_id' => $customer->id, 'title' => '二期']);
        $this->service->upsert(['customer_id' => $customer->id, 'title' => '二期', 'amount_untaxed' => 500_000]);
        $this->service->upsert(['prospect_name' => '長照', 'title' => '二期']);

        expect(Deal::count())->toBe(2)
            ->and(Deal::where('customer_id', $customer->id)->sole()->amount_untaxed)->toBe(500_000);
    });

    test('a stage in the attributes goes through changeStage and logs an event', function () {
        $deal = $this->service->upsert(['prospect_name' => 'A', 'title' => 'T', 'stage' => 'proposal']);

        $this->service->upsert(['id' => $deal->id, 'stage' => 'negotiation'], Source::Claude, stageNote: '客戶回覆報價');

        $event = DealEvent::sole();

        expect($deal->refresh()->stage)->toBe(DealStage::Negotiation)
            ->and($event)
            ->type->toBe(DealEventType::StageChange)
            ->from_stage->toBe(DealStage::Proposal)
            ->to_stage->toBe(DealStage::Negotiation)
            ->content->toBe('客戶回覆報價')
            ->source->toBe(Source::Claude);
    });

    test('creating a won deal closes it', function () {
        $deal = $this->service->upsert(['prospect_name' => 'A', 'title' => 'T', 'stage' => DealStage::Won, 'probability' => 60]);

        expect($deal->probability)->toBe(100)
            ->and($deal->closed_at)->not->toBeNull();
    });

    test('rejects a new deal without a party or title', function (array $attributes) {
        $this->service->upsert($attributes);
    })->with([
        'no party' => [['title' => 'T']],
        'no title' => [['prospect_name' => 'A']],
    ])->throws(InvalidArgumentException::class);
});

describe('changeStage', function () {
    test('won sets probability 100 and closed_at; reopening clears closed_at', function () {
        $deal = Deal::factory()->create(['stage' => DealStage::Negotiation, 'probability' => 70]);

        $this->service->changeStage($deal, DealStage::Won, '簽約', CarbonImmutable::parse('2026-09-20'));

        expect($deal->refresh())
            ->stage->toBe(DealStage::Won)
            ->probability->toBe(100)
            ->and($deal->closed_at->toDateString())->toBe('2026-09-20');

        $this->service->changeStage($deal, DealStage::Negotiation);

        expect($deal->refresh()->closed_at)->toBeNull()
            ->and(DealEvent::count())->toBe(2);
    });

    test('lost sets probability 0 and closed_at now', function () {
        $deal = Deal::factory()->create(['stage' => DealStage::Proposal]);

        $event = $this->service->changeStage($deal, DealStage::Lost);

        expect($deal->refresh())
            ->probability->toBe(0)
            ->and($deal->closed_at->toDateTimeString())->toBe('2026-09-26 10:00:00')
            ->and($event->occurred_on->toDateString())->toBe('2026-09-26');
    });

    test('the same stage is a no-op', function () {
        $deal = Deal::factory()->create(['stage' => DealStage::Proposal]);

        expect($this->service->changeStage($deal, DealStage::Proposal))->toBeNull()
            ->and(DealEvent::count())->toBe(0);
    });
});

describe('logEvent', function () {
    test('is idempotent with an external key', function () {
        $deal = Deal::factory()->create();

        $this->service->logEvent($deal, DealEventType::Meeting, '第一次會議', CarbonImmutable::parse('2026-09-20'), Source::Claude, 'm1');
        $event = $this->service->logEvent($deal, DealEventType::Meeting, '第一次會議（更正）', CarbonImmutable::parse('2026-09-20'), Source::Claude, 'm1');
        $this->service->logEvent($deal, DealEventType::Note, '沒有 key 的備註');

        expect(DealEvent::count())->toBe(2)
            ->and($event->refresh()->content)->toBe('第一次會議（更正）');
    });

    test('does not accept stage changes', function () {
        $this->service->logEvent(Deal::factory()->create(), DealEventType::StageChange, 'x');
    })->throws(InvalidArgumentException::class);

    test('does not touch the deal', function () {
        $deal = Deal::factory()->create();
        $this->travel(3)->days();

        $this->service->logEvent($deal, DealEventType::Note, 'n');

        expect($deal->refresh()->updated_at->toDateString())->toBe('2026-09-26');
    });
});

test('pipeline sums open deals by stage and lists deals without a next action', function () {
    Deal::factory()->create(['stage' => DealStage::Lead, 'amount_untaxed' => 100_000, 'probability' => 10]);
    Deal::factory()->create(['stage' => DealStage::Proposal, 'amount_untaxed' => 1_000_000, 'probability' => 30, 'next_action_on' => null]);
    $overdue = Deal::factory()->create(['stage' => DealStage::Negotiation, 'amount_untaxed' => 500_000, 'probability' => 60, 'next_action_on' => '2026-09-20']);
    Deal::factory()->create(['stage' => DealStage::Won, 'amount_untaxed' => 900_000, 'probability' => 100, 'next_action_on' => null]);
    Deal::factory()->create(['stage' => DealStage::Negotiation, 'amount_untaxed' => 100_000, 'probability' => 50, 'next_action_on' => '2026-09-26']);

    $pipeline = $this->service->pipeline();

    expect($pipeline)
        ->open_count->toBe(4)
        ->amount_total->toBe(1_700_000)
        ->weighted_total->toBe(10_000 + 300_000 + 300_000 + 50_000)
        ->and($pipeline['by_stage']['negotiation'])->toBe(['label' => '議價', 'count' => 2, 'amount' => 600_000, 'weighted' => 350_000])
        ->and($pipeline['by_stage']['lead']['count'])->toBe(1)
        ->and($pipeline['no_next_action']->pluck('next_action_on')->map(fn ($date) => $date?->toDateString())->all())->toBe([null, '2026-09-20'])
        ->and($pipeline['no_next_action']->last()->id)->toBe($overdue->id)
        ->and(Deal::query()->withoutNextAction()->count())->toBe(2);
});
