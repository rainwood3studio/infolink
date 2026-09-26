<?php

namespace App\Filament\Pages;

use App\Domain\Delivery\DeliverySummary;
use App\Filament\NavigationGroup;
use App\Filament\Resources\RedmineIssues\RedmineIssueResource;
use App\Filament\Widgets\DeliveryStatsWidget;
use App\Filament\Widgets\DeliveryTrendChart;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * 交付總覽: backlog cards, the backlog trend and a per-project ranking, all from DeliverySummary.
 */
class DeliveryOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Delivery;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = '交付總覽';

    protected static ?string $title = '交付總覽';

    protected static ?string $slug = 'delivery';

    protected ?string $subheading = '結案數不是產能指標（受驗收節奏影響），這裡看的是存量與流向。「驗證中」依被分派者拆成文豪隊列（等最終驗收）與非文豪（未走驗收流程）。';

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            DeliveryStatsWidget::class,
            DeliveryTrendChart::class,
        ];
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
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
            ->heading('各專案')
            ->records(fn (?string $sortColumn, ?string $sortDirection): Collection => $this->projectRows($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('name')
                    ->label('專案')
                    ->description(fn (array $record): string => $record['identifier'])
                    ->url(fn (array $record): string => RedmineIssueResource::getUrl('index', [
                        'filters' => ['project_identifier' => ['values' => [$record['identifier']]]],
                    ])),
                TextColumn::make('open')
                    ->label('未結案')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('verifying_acceptor')
                    ->label('驗證中－文豪')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('verifying_others')
                    ->label('驗證中－他人')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null)
                    ->sortable(),
                TextColumn::make('stalled_90d')
                    ->label('停滯 > 90 天')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('median_age_days')
                    ->label('中位年齡')
                    ->suffix(' 天')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->paginated(false);
    }

    /**
     * Per-project rows keyed by identifier, most open issues first unless another column is sorted.
     *
     * @return Collection<string, array{identifier:string, name:string, open:int, verifying_acceptor:int, verifying_others:int, stalled_90d:int, median_age_days:?int}>
     */
    protected function projectRows(?string $sortColumn, ?string $sortDirection): Collection
    {
        $rows = collect(app(DeliverySummary::class)->current()['projects'])->keyBy('identifier');

        return filled($sortColumn)
            ? $rows->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc')
            : $rows->sortByDesc('open');
    }
}
