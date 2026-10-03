<?php

namespace App\Filament\Pages;

use App\Domain\Delivery\ClosingBoard;
use App\Filament\NavigationGroup;
use App\Filament\Resources\RedmineIssues\RedmineIssueResource;
use App\Models\Project;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 結案作戰: one card per `closing` project (what is still open, who holds it, the burn-down and its straight-line
 * projection, the money hanging on it) and the selected project's open issues grouped by who they are stuck on.
 * Data comes from ClosingBoard.
 */
class ClosingProjects extends Page
{
    /** At most this many issues are listed per stage; the rest are reached through the issue mirror. */
    public const int ISSUES_PER_STAGE = 50;

    /** Holders listed on a card under 「卡在誰」. */
    public const int HOLDERS_PER_CARD = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Delivery;

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = '結案作戰';

    protected static ?string $title = '結案作戰';

    protected static ?string $slug = 'closing';

    protected string $view = 'filament.pages.closing.index';

    /** The selected project's id; null means the most urgent one. */
    #[Url]
    public ?string $project = null;

    public function selectProject(int $id): void
    {
        $this->project = (string) $id;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $acceptor = self::acceptor();

        return "未結議題依「卡在誰」分四類：未指派、開發中、等{$acceptor}驗收、驗證中但不在{$acceptor}名下（沒人會去驗收）。推估結案日是把近 7 天的淨消化速度拉成直線，只是估計，不是承諾。";
    }

    /**
     * Stage key => [label, what it means], in display order.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function stages(): array
    {
        $acceptor = self::acceptor();

        return [
            ClosingBoard::STAGE_UNASSIGNED => ['label' => '未指派', 'hint' => '還沒有人接'],
            ClosingBoard::STAGE_IN_PROGRESS => ['label' => '開發中', 'hint' => '已指派，還沒送驗'],
            ClosingBoard::STAGE_OFF_FLOW => ['label' => "驗證中‧非{$acceptor}", 'hint' => "狀態是驗證中，但不在{$acceptor}名下，沒人會去驗收"],
            ClosingBoard::STAGE_AWAITING_ACCEPTANCE => ['label' => "等{$acceptor}驗收", 'hint' => "已送驗，在{$acceptor}的驗收隊列"],
        ];
    }

    /**
     * The projection in words, and whether it is bad news.
     *
     * @param  array<string, mixed>  $project  a row of ClosingBoard::projects()
     * @return array{text: string, color: 'danger'|'success'|'gray'}
     */
    public static function projectionText(array $project): array
    {
        $days = $project['burn_from']['days_ago'] ?? ClosingBoard::BURN_WINDOW_DAYS;

        return match ($project['projection']) {
            ClosingBoard::PROJECTION_NOT_LINKED => ['text' => '未連結 Redmine 專案，無法推估', 'color' => 'gray'],
            ClosingBoard::PROJECTION_CLEARED => ['text' => 'Redmine 上已沒有未結議題', 'color' => 'success'],
            ClosingBoard::PROJECTION_NO_HISTORY => ['text' => '歷史資料不足，還無法推估', 'color' => 'gray'],
            ClosingBoard::PROJECTION_NOT_CONVERGING => [
                'text' => $project['burn_per_day'] < 0
                    ? sprintf('近 %d 天未結議題不減反增（%d → %d 張），照這樣結不完', $days, $project['burn_from']['open'], $project['open'])
                    : "近 {$days} 天未結議題沒有減少，照這樣結不完",
                'color' => 'danger',
            ],
            default => [
                'text' => sprintf(
                    '照近 %d 天速度（每天淨消化 %s 張）預計 %s 結完%s',
                    $days,
                    self::burnRate($project['burn_per_day']),
                    self::shortDate($project['projected_close_date']),
                    match (true) {
                        $project['days_late'] === null => '',
                        $project['days_late'] > 0 => "，比目標晚 {$project['days_late']} 天",
                        $project['days_late'] < 0 => '，比目標早 '.abs($project['days_late']).' 天',
                        default => '，剛好趕上目標日',
                    },
                ),
                'color' => match (true) {
                    $project['days_late'] === null => 'gray',
                    $project['days_late'] > 0 => 'danger',
                    default => 'success',
                },
            ],
        };
    }

    /**
     * m/d for a date in the current year, Y/m/d otherwise.
     */
    public static function shortDate(string $date): string
    {
        $parsed = CarbonImmutable::parse($date);

        return $parsed->format($parsed->isSameYear(today()) ? 'm/d' : 'Y/m/d');
    }

    protected static function burnRate(float $perDay): string
    {
        if ($perDay < 0.05) {
            return '不到 0.1';
        }

        return rtrim(rtrim(number_format($perDay, 1), '0'), '.');
    }

    protected static function acceptor(): string
    {
        return (string) config('services.redmine.acceptor_name');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $board = app(ClosingBoard::class);
        $projects = $board->projects();
        $selected = $this->project === null ? null : $projects->firstWhere('id', (int) $this->project);

        if ($selected === null) {
            $this->project = null;
            $selected = $projects->first();
        }

        $models = Project::query()->findMany($projects->pluck('id'))->keyBy('id');
        $issues = $selected === null ? collect() : $board->issues($models[$selected['id']]);

        return [
            'projects' => $projects,
            'models' => $models,
            'summary' => $board->summary($projects),
            'stages' => self::stages(),
            'acceptor' => self::acceptor(),
            'selected' => $selected,
            'issuesByStage' => $issues->groupBy('stage'),
            'issuesPerStage' => self::ISSUES_PER_STAGE,
            'holdersPerCard' => self::HOLDERS_PER_CARD,
            'mirrorUrl' => $selected === null || $selected['redmine_identifiers'] === []
                ? null
                : RedmineIssueResource::getUrl('index', [
                    'filters' => ['project_identifier' => ['values' => $selected['redmine_identifiers']]],
                ]),
        ];
    }
}
