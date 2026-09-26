<?php

namespace App\Filament\Pages;

use App\Domain\Finance\CashFlowAnalytics;
use App\Filament\NavigationGroup;
use App\Filament\Widgets\CashFlow\CashFlowKpiWidget;
use App\Filament\Widgets\CashFlow\CollectionPerformanceTable;
use App\Filament\Widgets\CashFlow\CustomerConcentrationChart;
use App\Filament\Widgets\CashFlow\CustomerRevenueChart;
use App\Filament\Widgets\CashFlow\DailyBalanceChart;
use App\Filament\Widgets\CashFlow\FixedCostTrendChart;
use App\Filament\Widgets\CashFlow\LargestInflowsTable;
use App\Filament\Widgets\CashFlow\LargestOutflowsTable;
use App\Filament\Widgets\CashFlow\MonthlyCashFlowChart;
use App\Filament\Widgets\CashFlow\OutflowCompositionChart;
use App\Filament\Widgets\CashFlow\OutflowStructureChart;
use App\Filament\Widgets\CashFlow\OutflowTypeChart;
use App\Filament\Widgets\CashFlow\RunwayHistoryChart;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use UnitEnum;

/**
 * 金流分析: multi-angle cash-flow charts over line-level bank transactions for a chosen period.
 *
 * The period lives in `filters` (query string `?filters[period]=6m`, or `?filters[period]=custom&filters[from]=
 * 2026-01-01&filters[to]=2026-06-30`); every widget reads it through {@see self::analyticsFor()}.
 */
class CashFlowAnalysis extends Page
{
    use HasFiltersForm;

    public const string DEFAULT_PERIOD = '12m';

    public const array PERIODS = [
        '3m' => '近 3 個月',
        '6m' => '近 6 個月',
        '12m' => '近 12 個月',
        'all' => '全部',
        'custom' => '自訂',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = '金流分析';

    protected static ?string $title = '金流分析';

    protected static ?string $slug = 'cash-flow';

    protected ?string $subheading = '現金基礎，依銀行逐筆交易計算；沒有逐筆資料的月份不列入（不補 0）。營收為入帳金額（含稅）。「近 N 個月」以最新一筆交易所在月份往回算。';

    /**
     * Resolve the (unvalidated) page filters to an analytics period; anything unrecognised falls back safely.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function analyticsFor(?array $filters): CashFlowAnalytics
    {
        $period = $filters['period'] ?? self::DEFAULT_PERIOD;

        return match ($period) {
            '3m' => CashFlowAnalytics::lastMonths(3),
            '6m' => CashFlowAnalytics::lastMonths(6),
            'all' => CashFlowAnalytics::between(),
            'custom' => self::customPeriod($filters['from'] ?? null, $filters['to'] ?? null),
            default => CashFlowAnalytics::lastMonths(12),
        };
    }

    protected static function customPeriod(mixed $from, mixed $to): CashFlowAnalytics
    {
        $bound = function (mixed $value, bool $end): ?string {
            if (! is_string($value)) {
                return null;
            }

            try {
                return CashFlowAnalytics::parseBound(substr(trim($value), 0, 10), $end)?->toDateString();
            } catch (InvalidArgumentException) {
                return null;
            }
        };

        try {
            return CashFlowAnalytics::between($bound($from, false), $bound($to, true));
        } catch (InvalidArgumentException) {
            return CashFlowAnalytics::between();
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('period')
                        ->label('期間')
                        ->options(self::PERIODS)
                        ->default(self::DEFAULT_PERIOD)
                        ->selectablePlaceholder(false)
                        ->live(),
                    DatePicker::make('from')
                        ->label('起')
                        ->visible(fn (Get $get): bool => $get('period') === 'custom'),
                    DatePicker::make('to')
                        ->label('迄')
                        ->visible(fn (Get $get): bool => $get('period') === 'custom'),
                ])
                ->columns(3),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(['md' => 2])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            CashFlowKpiWidget::class,
            MonthlyCashFlowChart::class,
            OutflowTypeChart::class,
            OutflowStructureChart::class,
            OutflowCompositionChart::class,
            CustomerRevenueChart::class,
            CustomerConcentrationChart::class,
            FixedCostTrendChart::class,
            RunwayHistoryChart::class,
            DailyBalanceChart::class,
            CollectionPerformanceTable::class,
            LargestInflowsTable::class,
            LargestOutflowsTable::class,
        ];
    }
}
