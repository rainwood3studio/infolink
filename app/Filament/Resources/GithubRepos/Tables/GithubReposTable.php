<?php

namespace App\Filament\Resources\GithubRepos\Tables;

use App\Models\Deal;
use App\Models\GithubRepo;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The repo list doubles as the mapping screen behind 專案損益: each repo is tied inline to a project or, for work
 * that has no contract yet, to a deal; 「分支對應」 sends single branches to another project.
 */
class GithubReposTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('commits'))
            ->columns([
                TextColumn::make('name')
                    ->label('Repo')
                    ->description(fn (GithubRepo $record): string => $record->owner)
                    ->url(fn (GithubRepo $record): string => $record->url(), shouldOpenInNewTab: true)
                    ->searchable(['name', 'full_name'])
                    ->sortable(),
                SelectColumn::make('project_id')
                    ->label('專案')
                    ->options(fn (): array => self::projectOptions())
                    ->placeholder('未指定'),
                TextColumn::make('branch_projects')
                    ->label('分支對應')
                    ->state(fn (GithubRepo $record): array => self::branchProjectLabels($record))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                SelectColumn::make('deal_id')
                    ->label('業務機會（尚未簽約）')
                    ->options(fn (): array => self::dealOptions())
                    ->placeholder('未指定'),
                IconColumn::make('is_archived')
                    ->label('封存')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('commits_count')
                    ->label('commits')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('pushed_at')
                    ->label('最後 push')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('最後同步')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('pushed_at', 'desc')
            ->filters([
                TernaryFilter::make('is_archived')
                    ->label('封存'),
                TernaryFilter::make('mapped')
                    ->label('對應狀態')
                    ->placeholder('全部')
                    ->trueLabel('已對應')
                    ->falseLabel('未對應')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNotNull('project_id')->orWhereNotNull('deal_id')),
                        false: fn (Builder $query): Builder => $query->whereNull('project_id')->whereNull('deal_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                self::branchProjectsAction(),
            ]);
    }

    /**
     * 「分支對應」: edit which branches of the repo belong to another project than the repo's own.
     */
    public static function branchProjectsAction(): Action
    {
        return Action::make('branchProjects')
            ->label('分支對應')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(fn (GithubRepo $record): string => "分支對應：{$record->name}")
            ->modalDescription('同一個 repo 的某個分支其實是另一個專案時才需要設定。沒列在這裡的分支，都算在這個 repo 指定的專案（或業務機會）。')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('儲存')
            ->fillForm(fn (GithubRepo $record): array => ['branch_projects' => $record->branch_projects ?? []])
            ->schema(fn (GithubRepo $record): array => [
                Repeater::make('branch_projects')
                    ->hiddenLabel()
                    ->schema([
                        TextInput::make('branch')
                            ->label('分支')
                            ->datalist($record->branches()->orderBy('name')->pluck('name')->all())
                            ->helperText('要和 GitHub 上的分支名稱完全一樣')
                            ->required()
                            ->distinct()
                            ->maxLength(255),
                        Select::make('project_id')
                            ->label('專案')
                            ->options(self::projectOptions())
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('新增分支對應'),
            ])
            ->action(function (GithubRepo $record, array $data): void {
                $record->update(['branch_projects' => GithubRepo::normalizeBranchProjects($data['branch_projects'] ?? [])]);

                Notification::make()->title('已儲存分支對應')->success()->send();
            });
    }

    /**
     * 「dycare-dev → HR 系統」 for every branch override of the repo.
     *
     * @return list<string>
     */
    public static function branchProjectLabels(GithubRepo $repo): array
    {
        $overrides = $repo->branch_projects ?? [];

        if ($overrides === []) {
            return [];
        }

        $projects = self::projectOptions();

        return array_map(
            fn (array $override): string => $override['branch'].' → '.($projects[$override['project_id']] ?? '（已刪除的專案）'),
            $overrides,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function projectOptions(): array
    {
        return Project::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Open deals, plus closed ones a repo is still tied to (so the select keeps showing them), as 「對象｜標題」.
     *
     * @return array<int, string>
     */
    public static function dealOptions(): array
    {
        return Deal::query()
            ->with('customer')
            ->where(fn (Builder $query): Builder => $query
                ->open()
                ->orWhereIn('id', GithubRepo::query()->whereNotNull('deal_id')->select('deal_id')))
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (Deal $deal): array => [
                $deal->id => $deal->party_name.'｜'.$deal->title.($deal->stage->isClosed() ? "（{$deal->stage->getLabel()}）" : ''),
            ])
            ->all();
    }
}
