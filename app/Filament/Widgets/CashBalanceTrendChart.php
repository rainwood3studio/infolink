<?php

namespace App\Filament\Widgets;

use App\Models\BankTransaction;
use Filament\Widgets\ChartWidget;

/**
 * 現金與推估: month-end bank balance (all accounts) for the last 24 months. Only shown on that page.
 */
class CashBalanceTrendChart extends ChartWidget
{
    public const int MONTHS = 24;

    protected static bool $isDiscovered = false;

    protected ?string $heading = '月底餘額趨勢';

    protected ?string $description = '各帳戶每月最後一筆交易的餘額加總；當月沒有交易的帳戶沿用前一筆。';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * Month-end balance per `Y-m`, oldest first; the latest month is as of its last transaction.
     *
     * @return array<string, int>
     */
    public static function monthEndBalances(int $months = self::MONTHS): array
    {
        $transactions = BankTransaction::query()
            ->orderBy('txn_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['bank_account_id', 'txn_date', 'sequence', 'balance']);

        if ($transactions->isEmpty()) {
            return [];
        }

        /** @var array<string, array<int, int>> $lastByMonth month => account => balance */
        $lastByMonth = [];

        foreach ($transactions as $transaction) {
            $lastByMonth[$transaction->txn_date->format('Y-m')][$transaction->bank_account_id] = $transaction->balance;
        }

        $first = $transactions->first()->txn_date->startOfMonth();
        $last = $transactions->last()->txn_date->startOfMonth();
        $current = [];
        $balances = [];

        for ($month = $first->toImmutable(); $month->lte($last); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $current = array_replace($current, $lastByMonth[$key] ?? []);
            $balances[$key] = array_sum($current);
        }

        return array_slice($balances, -$months, preserve_keys: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $balances = self::monthEndBalances();

        return [
            'datasets' => [
                [
                    'label' => '月底餘額',
                    'data' => array_values($balances),
                    'borderColor' => '#2563eb',
                    'backgroundColor' => '#2563eb',
                ],
            ],
            'labels' => array_keys($balances),
        ];
    }
}
