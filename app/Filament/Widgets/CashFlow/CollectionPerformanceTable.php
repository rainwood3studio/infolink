<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\Money;
use App\Filament\Widgets\CashFlow\Concerns\ReadsCashFlowPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * 金流分析: how punctually each customer paid the receivables received in the period.
 */
class CollectionPerformanceTable extends TableWidget
{
    use ReadsCashFlowPeriod;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('收款準時度')
            ->description(function (): string {
                ['overall' => $overall, 'overdue' => $overdue] = $this->analytics()->collectionPerformance();

                return sprintf(
                    '期間內已收款的應收，實收日 vs 預計收款日（晚於預計日才算遲，負數為提早）。整體 %d 筆、準時 %s、平均 %s 天；目前逾期 %d 筆 %s。',
                    $overall['count'],
                    $overall['on_time_rate_pct'] === null ? '—' : $overall['on_time_rate_pct'].'%',
                    $overall['avg_days_late'] ?? '—',
                    $overdue['count'],
                    Money::format($overdue['amount_taxed']),
                );
            })
            ->records(fn (): Collection => collect($this->analytics()->collectionPerformance()['by_customer'])->keyBy('customer'))
            ->columns([
                TextColumn::make('customer')
                    ->label('客戶'),
                TextColumn::make('count')
                    ->label('筆數')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('on_time_rate_pct')
                    ->label('準時率')
                    ->suffix('%')
                    ->alignEnd()
                    ->color(fn (float $state): string => $state >= 80 ? 'success' : ($state >= 50 ? 'warning' : 'danger')),
                TextColumn::make('avg_days_late')
                    ->label('平均遲延（天）')
                    ->alignEnd(),
                TextColumn::make('max_days_late')
                    ->label('最久（天）')
                    ->alignEnd(),
                Money::column(TextColumn::make('amount_taxed'))
                    ->label('實收（含稅）'),
            ])
            ->emptyStateHeading('這段期間沒有已收款的應收')
            ->paginated(false);
    }
}
