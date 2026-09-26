<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\Money;
use App\Filament\Widgets\CashFlow\Concerns\ReadsCashFlowPeriod;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * 金流分析: the period's five biggest single deposits or withdrawals.
 */
abstract class LargestLinesTable extends TableWidget
{
    use ReadsCashFlowPeriod;

    protected static bool $isDiscovered = false;

    /**
     * @return 'deposit'|'withdrawal'
     */
    abstract protected function side(): string;

    abstract protected function tableHeading(): string;

    abstract protected function tableDescription(): string;

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->tableHeading())
            ->description($this->tableDescription())
            ->records(fn (): Collection => collect($this->analytics()->largestLines($this->side()))->mapWithKeys(fn (array $line, int $index): array => ["{$line['date']}-{$index}" => $line]))
            ->columns([
                TextColumn::make('date')
                    ->label('日期'),
                TextColumn::make('summary')
                    ->label('摘要')
                    ->description(fn (array $record): ?string => $record['counterparty'])
                    ->wrap(),
                TextColumn::make('category_label')
                    ->label('分類')
                    ->badge(),
                IconColumn::make('is_one_off')
                    ->label('一次性')
                    ->boolean(),
                Money::column(TextColumn::make('amount'))
                    ->label('金額'),
            ])
            ->emptyStateHeading('這段期間沒有交易')
            ->paginated(false);
    }
}
