<?php

namespace App\Domain\Finance;

use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Suggests which outstanding receivables a bank deposit settles, and settles them.
 *
 * A candidate deposit is a bank line of category `revenue` with `deposit > 0` and no `receivable_id`, dated within the
 * last {@see self::DEFAULT_MONTHS} months (older lines are assumed reconciled by hand). Its customer comes from the
 * counterparty through {@see CounterpartyMatcher}; a deposit nobody matches is listed under `unmatched_counterparties`
 * so an alias can be added. Among that customer's outstanding (planned or invoiced) receivables, recurring included:
 * - `exact_taxed`: the deposit equals the receivable's taxed amount — the normal case;
 * - `exact_untaxed`: it equals the untaxed amount (the customer paid without VAT);
 * - `combination`: it equals the taxed amounts of 2 or 3 receivables added up (one remittance for several invoices;
 *   only the {@see self::MAX_COMBINATION_POOL} nearest-due receivables are combined);
 * - `near`: within 1% or NT$100 of the taxed amount (remittance fees, rounding).
 * Suggestions are ranked exact first, then combination, then near, and within a kind by how close the receivable's
 * `expected_on` is to the deposit date. `best` is the top suggestion when it is exact or a combination AND the deposit
 * is within {@see self::MAX_BEST_DAYS} days of the receivable's `expected_on` — a `near` match, or an exact match to a
 * receivable due months away (a monthly fee of a different period, typically), is only ever offered, never
 * pre-selected (`far` = true). Amounts are whole NTD; `amount_taxed` is what the bank should show.
 */
class DepositMatcher
{
    /** Window of unsettled deposits to look at. */
    public const int DEFAULT_MONTHS = 6;

    /** An exact match is only pre-selected when the deposit is within this many days of the receivable's expected_on. */
    public const int MAX_BEST_DAYS = 60;

    /** A `near` match may differ from the taxed amount by this fraction … */
    public const float NEAR_RATIO = 0.01;

    /** … or by this many NTD, whichever is larger. */
    public const int NEAR_AMOUNT = 100;

    /** Receivables (nearest expected_on first) considered for 2–3 way combinations. */
    public const int MAX_COMBINATION_POOL = 12;

    /** @var list<string> Match kinds, best first. */
    public const array KINDS = ['exact_taxed', 'exact_untaxed', 'combination', 'near'];

    /**
     * @return array{
     *     window_from: string,
     *     deposits: list<array{transaction: array{id: int, date: string, counterparty: ?string, summary: string, deposit: int}, customer: array{id: int, short_name: string}, suggestions: list<array<string, mixed>>, best: ?array<string, mixed>, outstanding_count: int}>,
     *     unmatched_counterparties: list<array{transaction: array{id: int, date: string, counterparty: ?string, summary: string, deposit: int}}>,
     *     summary: array{deposits: int, with_best: int, with_suggestions: int, unmatched: int, total: int},
     * }
     */
    public function suggest(?CarbonImmutable $today = null, int $months = self::DEFAULT_MONTHS): array
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $from = $today->subMonths($months)->startOfMonth();

        $lines = $this->unsettledQuery()
            ->whereDate('txn_date', '>=', $from)
            ->orderByDesc('txn_date')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->get();

        $matcher = new CounterpartyMatcher;
        $outstanding = $this->outstanding()->groupBy('customer_id');

        $deposits = [];
        $unmatched = [];

        foreach ($lines as $line) {
            $customer = $matcher->match($line->counterparty);

            if ($customer === null) {
                $unmatched[] = ['transaction' => self::presentTransaction($line)];

                continue;
            }

            $deposits[] = $this->describe($line, $customer, $outstanding->get($customer->id, collect()));
        }

        return [
            'window_from' => $from->toDateString(),
            'deposits' => $deposits,
            'unmatched_counterparties' => $unmatched,
            'summary' => [
                'deposits' => count($deposits) + count($unmatched),
                'with_best' => count(array_filter($deposits, fn (array $deposit): bool => $deposit['best'] !== null)),
                'with_suggestions' => count(array_filter($deposits, fn (array $deposit): bool => $deposit['suggestions'] !== [])),
                'unmatched' => count($unmatched),
                'total' => (int) $lines->sum('deposit'),
            ],
        ];
    }

    /**
     * The suggestions for one deposit, regardless of the window; null when the line is not an unsettled revenue deposit
     * or its counterparty matches no customer.
     *
     * @return array{transaction: array{id: int, date: string, counterparty: ?string, summary: string, deposit: int}, customer: array{id: int, short_name: string}, suggestions: list<array<string, mixed>>, best: ?array<string, mixed>, outstanding_count: int}|null
     */
    public function suggestFor(BankTransaction $transaction): ?array
    {
        if ($transaction->category !== TransactionCategory::Revenue || $transaction->deposit <= 0 || $transaction->receivable_id !== null) {
            return null;
        }

        $customer = (new CounterpartyMatcher)->match($transaction->counterparty);

        if ($customer === null) {
            return null;
        }

        return $this->describe($transaction, $customer, $this->outstanding()->where('customer_id', $customer->id));
    }

    /**
     * Mark every receivable received on `$receivedOn` and link the deposit to the first one; a bank line has a single
     * `receivable_id`, so the other ids are written into its `notes` (「同筆入帳亦沖銷應收 #12、#13」).
     *
     * @param  list<int>  $receivableIds
     * @return Collection<int, Receivable> The settled receivables, in the order given.
     *
     * @throws InvalidArgumentException when no ids are given, one is unknown or not outstanding, or the deposit is already linked.
     */
    public function settle(BankTransaction $transaction, array $receivableIds, CarbonInterface $receivedOn): Collection
    {
        $receivableIds = array_values(array_unique(array_map(intval(...), $receivableIds)));

        if ($receivableIds === []) {
            throw new InvalidArgumentException('至少要選一筆應收。');
        }

        if ($transaction->receivable_id !== null) {
            throw new InvalidArgumentException("入帳 #{$transaction->id} 已經沖銷過應收 #{$transaction->receivable_id}。");
        }

        $receivables = Receivable::query()->findMany($receivableIds)->keyBy('id');

        foreach ($receivableIds as $id) {
            $receivable = $receivables->get($id);

            if ($receivable === null) {
                throw new InvalidArgumentException("找不到應收 #{$id}。");
            }

            if (! in_array($receivable->status, [ReceivableStatus::Planned, ReceivableStatus::Invoiced], true)) {
                throw new InvalidArgumentException("應收 #{$id}「{$receivable->item}」不是未收狀態（{$receivable->status->getLabel()}）。");
            }
        }

        return DB::transaction(function () use ($transaction, $receivableIds, $receivables, $receivedOn): Collection {
            $service = app(ReceivableService::class);
            $settled = collect();

            foreach ($receivableIds as $index => $id) {
                $settled->push($service->markReceived($receivables->get($id), $receivedOn, $index === 0 ? $transaction : null));
            }

            if (count($receivableIds) > 1) {
                $others = implode('、', array_map(fn (int $id): string => "#{$id}", array_slice($receivableIds, 1)));
                $note = "同筆入帳亦沖銷應收 {$others}";
                $transaction->notes = filled($transaction->notes) ? rtrim($transaction->notes)."\n".$note : $note;
                $transaction->save();
            }

            return $settled;
        });
    }

    /**
     * Outstanding receivables, recurring included, as Select options 「客戶 ｜ 項目 NT$含稅 (預計 2026/10/31)」.
     *
     * @return array<int, string>
     */
    public function outstandingOptions(?int $customerId = null): array
    {
        return $this->outstanding()
            ->when($customerId !== null, fn (Collection $rows): Collection => $rows->where('customer_id', $customerId))
            ->mapWithKeys(fn (Receivable $receivable): array => [$receivable->id => self::optionLabel($receivable)])
            ->all();
    }

    public static function optionLabel(Receivable $receivable): string
    {
        $customer = $receivable->customer?->short_name ?: $receivable->customer?->name;
        $project = $receivable->project?->name;

        return implode('｜', array_filter([$customer, $project, $receivable->item]))
            .'　NT$'.number_format($receivable->amount_taxed).'（預計 '.$receivable->expected_on->format('Y/m/d').'）';
    }

    /**
     * @param  Collection<int, Receivable>  $outstanding  The customer's outstanding receivables.
     * @return array{transaction: array{id: int, date: string, counterparty: ?string, summary: string, deposit: int}, customer: array{id: int, short_name: string}, suggestions: list<array<string, mixed>>, best: ?array<string, mixed>, outstanding_count: int}
     */
    protected function describe(BankTransaction $line, Customer $customer, Collection $outstanding): array
    {
        $suggestions = $this->suggestions($line, $outstanding);
        $best = $suggestions[0] ?? null;

        return [
            'transaction' => self::presentTransaction($line),
            'customer' => ['id' => $customer->id, 'short_name' => $customer->short_name ?: $customer->name],
            'suggestions' => $suggestions,
            'best' => $best !== null && $best['kind'] !== 'near' && ! $best['far'] ? $best : null,
            'outstanding_count' => $outstanding->count(),
        ];
    }

    /**
     * @param  Collection<int, Receivable>  $outstanding
     * @return list<array{kind: string, kind_label: string, receivable_ids: list<int>, receivable_id: int, label: string, amount_taxed: int, difference: int, expected_on: string, days_from_deposit: int, far: bool, receivables: list<array{id: int, item: string, project: ?string, amount_taxed: int, amount_untaxed: int, expected_on: string, status: string}>}>
     */
    protected function suggestions(BankTransaction $line, Collection $outstanding): array
    {
        $deposit = (int) $line->deposit;
        $date = CarbonImmutable::instance($line->txn_date)->startOfDay();
        $suggestions = [];

        foreach ($outstanding as $receivable) {
            $kind = match (true) {
                $receivable->amount_taxed === $deposit => 'exact_taxed',
                $receivable->amount_untaxed === $deposit => 'exact_untaxed',
                abs($receivable->amount_taxed - $deposit) <= max(self::NEAR_AMOUNT, (int) round($receivable->amount_taxed * self::NEAR_RATIO)) => 'near',
                default => null,
            };

            if ($kind !== null) {
                $suggestions[] = $this->suggestion($kind, [$receivable], $deposit, $date);
            }
        }

        $pool = $outstanding
            ->sortBy(fn (Receivable $receivable): int => abs((int) CarbonImmutable::instance($receivable->expected_on)->diffInDays($date, false)))
            ->take(self::MAX_COMBINATION_POOL)
            ->values();

        foreach ($this->combinations($pool->all(), $deposit) as $combination) {
            $suggestions[] = $this->suggestion('combination', $combination, $deposit, $date);
        }

        usort($suggestions, fn (array $a, array $b): int => [array_search($a['kind'], self::KINDS, true), $a['days_from_deposit'], $a['receivable_id']]
            <=> [array_search($b['kind'], self::KINDS, true), $b['days_from_deposit'], $b['receivable_id']]);

        return $suggestions;
    }

    /**
     * Sets of 2 or 3 receivables whose taxed amounts add up exactly to the deposit.
     *
     * @param  list<Receivable>  $pool
     * @return list<list<Receivable>>
     */
    protected function combinations(array $pool, int $deposit): array
    {
        $found = [];
        $count = count($pool);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pair = $pool[$i]->amount_taxed + $pool[$j]->amount_taxed;

                if ($pair === $deposit) {
                    $found[] = [$pool[$i], $pool[$j]];

                    continue;
                }

                if ($pair >= $deposit) {
                    continue;
                }

                for ($k = $j + 1; $k < $count; $k++) {
                    if ($pair + $pool[$k]->amount_taxed === $deposit) {
                        $found[] = [$pool[$i], $pool[$j], $pool[$k]];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<Receivable>  $receivables
     * @return array{kind: string, kind_label: string, receivable_ids: list<int>, receivable_id: int, label: string, amount_taxed: int, difference: int, expected_on: string, days_from_deposit: int, far: bool, receivables: list<array{id: int, item: string, project: ?string, amount_taxed: int, amount_untaxed: int, expected_on: string, status: string}>}
     */
    protected function suggestion(string $kind, array $receivables, int $deposit, CarbonImmutable $date): array
    {
        $taxed = array_sum(array_map(fn (Receivable $receivable): int => $receivable->amount_taxed, $receivables));
        $nearest = min(array_map(fn (Receivable $receivable): int => (int) abs(CarbonImmutable::instance($receivable->expected_on)->diffInDays($date, false)), $receivables));
        $earliest = min(array_map(fn (Receivable $receivable): string => $receivable->expected_on->toDateString(), $receivables));

        return [
            'kind' => $kind,
            'kind_label' => self::kindLabel($kind),
            'receivable_ids' => array_map(fn (Receivable $receivable): int => $receivable->id, $receivables),
            'receivable_id' => $receivables[0]->id,
            'label' => implode(' + ', array_map(fn (Receivable $receivable): string => implode('｜', array_filter([$receivable->project?->name, $receivable->item])), $receivables)),
            'amount_taxed' => $taxed,
            'difference' => $deposit - $taxed,
            'expected_on' => $earliest,
            'days_from_deposit' => $nearest,
            'far' => $nearest > self::MAX_BEST_DAYS,
            'receivables' => array_map(fn (Receivable $receivable): array => [
                'id' => $receivable->id,
                'item' => $receivable->item,
                'project' => $receivable->project?->name,
                'amount_taxed' => $receivable->amount_taxed,
                'amount_untaxed' => $receivable->amount_untaxed,
                'expected_on' => $receivable->expected_on->toDateString(),
                'status' => $receivable->status->value,
            ], $receivables),
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'exact_taxed' => '金額相符（含稅）',
            'exact_untaxed' => '金額相符（未稅）',
            'combination' => '多筆合計相符',
            default => '金額接近',
        };
    }

    /**
     * @return array{id: int, date: string, counterparty: ?string, summary: string, deposit: int}
     */
    public static function presentTransaction(BankTransaction $line): array
    {
        return [
            'id' => $line->id,
            'date' => $line->txn_date->toDateString(),
            'counterparty' => filled($line->counterparty) ? trim($line->counterparty) : null,
            'summary' => $line->summary,
            'deposit' => (int) $line->deposit,
        ];
    }

    /**
     * @return Builder<BankTransaction>
     */
    protected function unsettledQuery()
    {
        return BankTransaction::query()
            ->where('category', TransactionCategory::Revenue)
            ->where('deposit', '>', 0)
            ->whereNull('receivable_id');
    }

    /**
     * Every outstanding receivable (planned or invoiced, recurring included) with customer and project loaded.
     *
     * @return Collection<int, Receivable>
     */
    protected function outstanding(): Collection
    {
        return Receivable::query()
            ->with(['customer:id,short_name,name', 'project:id,name'])
            ->outstanding()
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get();
    }
}
