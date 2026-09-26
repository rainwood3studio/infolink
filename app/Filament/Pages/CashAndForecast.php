<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Filament\Widgets\CashBalanceTrendChart;
use App\Filament\Widgets\CurrentForecastChart;
use App\Filament\Widgets\ForecastVersionsChart;
use App\Models\CashForecast;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 現金與推估: month-end balance history, the live forecast, and how saved forecast snapshots have moved.
 */
class CashAndForecast extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = '現金與推估';

    protected static ?string $title = '現金與推估';

    protected static ?string $slug = 'cash';

    protected ?string $subheading = '推估只計高確定性應收（含稅，依預計收款月；已逾期的算在本月）加上已排定的現金流，扣除月成本基準；本月只扣尚未支出的部分。';

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            CashBalanceTrendChart::class,
            CurrentForecastChart::class,
            ForecastVersionsChart::class,
        ];
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return ['md' => 2];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('推估快照')
            ->query(CashForecast::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('as_of')
                    ->label('基準日')
                    ->date()
                    ->sortable(),
                SourceFields::column()->toggleable(false),
                TextColumn::make('actor')
                    ->label('寫入者'),
                Money::column(TextColumn::make('opening_balance'))
                    ->label('期初餘額')
                    ->toggleable(isToggledHiddenByDefault: true),
                Money::column(TextColumn::make('year_end_balance'))
                    ->label('推估年底')
                    ->sortable(),
                Money::column(TextColumn::make('min_balance'))
                    ->label('推估最低')
                    ->description(fn (CashForecast $record): ?string => $record->min_balance_month)
                    ->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }
}
