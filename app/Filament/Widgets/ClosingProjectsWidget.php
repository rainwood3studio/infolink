<?php

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Money;
use App\Models\Project;
use App\Models\RedmineIssue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * 結案專案進度: every `closing` project with days to its target date, open Redmine issues and receivables still to collect.
 */
class ClosingProjectsWidget extends TableWidget
{
    /** Days to target at or below which the countdown turns red (matches the 結案專案風險 alert rule default). */
    public const int URGENT_DAYS = 30;

    protected static ?int $sort = 6;

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('結案專案進度')
            ->query(fn (): Builder => Project::query()
                ->with('customer')
                ->where('status', ProjectStatus::Closing)
                ->addSelect(['open_issues_count' => RedmineIssue::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('redmine_issues.project_id', 'projects.redmine_project_id')
                    ->where('is_closed', false)])
                ->withSum(['receivables as outstanding_taxed' => fn (Builder $query): Builder => $query->outstanding()], 'amount_taxed')
                ->orderByRaw('target_close_date is null')
                ->orderBy('target_close_date')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('專案')
                    ->description(fn (Project $record): ?string => $record->customer?->short_name ?? $record->customer?->name)
                    ->url(fn (Project $record): string => ProjectResource::getUrl('edit', ['record' => $record]))
                    ->wrap(),
                TextColumn::make('target_close_date')
                    ->label('目標日')
                    ->state(fn (Project $record): string => self::countdown($record))
                    ->description(fn (Project $record): ?string => $record->target_close_date?->format('Y-m-d'))
                    ->badge()
                    ->color(fn (Project $record): string => self::countdownColor($record)),
                TextColumn::make('open_issues_count')
                    ->label('未結議題')
                    ->state(fn (Project $record): string => $record->redmine_project_id === null
                        ? '未連結 Redmine'
                        : number_format((int) $record->open_issues_count))
                    ->badge(fn (Project $record): bool => $record->redmine_project_id === null)
                    ->color(fn (Project $record): ?string => $record->redmine_project_id === null ? 'gray' : null)
                    ->alignEnd(),
                TextColumn::make('outstanding_taxed')
                    ->label('未收應收（含稅）')
                    ->state(fn (Project $record): string => Money::format((int) $record->outstanding_taxed))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('目前沒有結案中的專案')
            ->paginated(false);
    }

    public static function countdown(Project $project): string
    {
        if ($project->target_close_date === null) {
            return '未設定目標日';
        }

        $daysLeft = (int) today()->diffInDays($project->target_close_date, false);

        return match (true) {
            $daysLeft > 0 => "剩 {$daysLeft} 天",
            $daysLeft === 0 => '今天',
            default => '已過 '.abs($daysLeft).' 天',
        };
    }

    public static function countdownColor(Project $project): string
    {
        if ($project->target_close_date === null) {
            return 'warning';
        }

        return today()->diffInDays($project->target_close_date, false) <= self::URGENT_DAYS ? 'danger' : 'gray';
    }
}
