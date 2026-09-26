<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;

/**
 * An unpersisted month-end cash forecast. Shape mirrors the `cash_forecasts` table.
 *
 * @phpstan-type ForecastRow array{month:string, inflow:int, outflow:int, balance:int, items:list<array{label:string, amount:int}>}
 */
final readonly class ForecastResult
{
    /**
     * @param  list<ForecastRow>  $rows  One row per month, balances are month-end.
     * @param  array<string, mixed>  $assumptions
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public int $openingBalance,
        public array $rows,
        public int $minBalance,
        public string $minBalanceMonth,
        public int $yearEndBalance,
        public array $assumptions,
    ) {}

    /**
     * @return array{as_of:string, opening_balance:int, rows:list<ForecastRow>, assumptions:array<string, mixed>, min_balance:int, min_balance_month:string, year_end_balance:int}
     */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf->toDateString(),
            'opening_balance' => $this->openingBalance,
            'rows' => $this->rows,
            'assumptions' => $this->assumptions,
            'min_balance' => $this->minBalance,
            'min_balance_month' => $this->minBalanceMonth,
            'year_end_balance' => $this->yearEndBalance,
        ];
    }
}
