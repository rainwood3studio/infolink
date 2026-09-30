<?php

namespace App\Filament\Pages;

use App\Domain\Alerts\Rules\ServerDiskRule;
use App\Domain\Infra\ServerDiskCollector;
use App\Domain\Infra\ServerDiskReport;
use App\Enums\SyncStatus;
use App\Filament\NavigationGroup;
use App\Filament\Widgets\ServerDiskStatsWidget;
use App\Filament\Widgets\ServerDiskTrendChart;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * 硬碟空間: every filesystem of the SSM managed servers, fullest first, with 7-day growth and days until full.
 */
class ServerDisks extends Page implements HasTable
{
    use InteractsWithTable;

    /** Usage (%) at which a bar turns amber / red; mirrors the default `server-disk` alert rule thresholds. */
    public const int WARNING_PERCENT = 80;

    public const int DANGER_PERCENT = 90;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ops;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = '硬碟空間';

    protected static ?string $title = '伺服器硬碟空間';

    protected static ?string $slug = 'server-disks';

    protected ?string $subheading = '透過 AWS SSM 對每台受管機器執行唯讀 df，每小時 :15 收集一次。使用率同 df（保留區塊算已用）；增長與「預計滿」依近 7 天線性推估。';

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ServerDiskStatsWidget::class,
            ServerDiskTrendChart::class,
        ];
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('collect')
                ->label('立即更新')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $run = app(ServerDiskCollector::class)->collect();

                    if ($run->status === SyncStatus::Ok) {
                        Notification::make()
                            ->title('已更新')
                            ->body("{$run->stats['collected']} 台成功，{$run->stats['unreachable']} 台無法取得")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()->title('更新失敗')->body($run->error)->danger()->persistent()->send();
                    }

                    $this->redirect(static::getUrl());
                }),
        ];
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
            ->heading('各分割區')
            ->records(fn (?string $sortColumn, ?string $sortDirection): Collection => $this->diskRows($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('name')
                    ->label('機器')
                    ->description(fn (array $record): string => collect([$record['instance_id'], $record['account'], $record['instance_type']])->filter()->implode(' · '))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mount')
                    ->label('掛載點')
                    ->description(fn (array $record): string => collect([$record['filesystem'], $record['fs_type']])->filter()->implode(' · ')),
                ViewColumn::make('used_percent')
                    ->label('使用率')
                    ->view('filament.tables.columns.disk-usage-bar')
                    ->sortable(),
                TextColumn::make('available_bytes')
                    ->label('剩餘')
                    ->formatStateUsing(fn (int $state): string => ServerDiskRule::gigabytes($state).' GB')
                    ->description(fn (array $record): string => '共 '.ServerDiskRule::gigabytes($record['size_bytes']).' GB')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('growth_bytes_per_day')
                    ->label('近 7 天增長')
                    ->formatStateUsing(fn (float $state): string => ($state > 0 ? '+' : '').ServerDiskRule::gigabytes($state).' GB／天')
                    ->placeholder('資料不足')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('days_to_full')
                    ->label('預計滿')
                    ->formatStateUsing(fn (int $state): string => $state > 365 ? '> 1 年' : "{$state} 天")
                    ->placeholder('—')
                    ->color(fn (?int $state): ?string => match (true) {
                        $state === null => null,
                        $state <= 14 => 'danger',
                        $state <= 60 => 'warning',
                        default => null,
                    })
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('collected_at')
                    ->label('收集時間')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->alignEnd(),
            ])
            ->paginated(false);
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    protected function diskRows(?string $sortColumn, ?string $sortDirection): Collection
    {
        $rows = app(ServerDiskReport::class)->current();

        if (filled($search = $this->getTableSearch())) {
            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower("{$row['name']} {$row['instance_id']} {$row['mount']}"), mb_strtolower($search)));
        }

        return filled($sortColumn)
            ? $rows->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc')
            : $rows;
    }
}
