<?php

namespace App\Filament\Pages;

use App\Domain\Engineering\DevActivityReport;
use App\Domain\Engineering\GithubSync;
use App\Domain\Engineering\RedmineActivity;
use App\Enums\ReportType;
use App\Enums\SyncStatus;
use App\Filament\NavigationGroup;
use App\Models\Report;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 開發活動: what each person did on GitHub — per-person cards, a person × day heatmap, the daily work log with the
 * Redmine issues they touched, and the commits that reference no issue. Data comes from DevActivityReport.
 */
class DevActivity extends Page
{
    /** For 今天 / 昨天 the heatmap shows this many trailing days instead of a single column. */
    public const int SHORT_PERIOD_HEATMAP_DAYS = 14;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Delivery;

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = '開發活動';

    protected static ?string $title = '開發活動';

    protected static ?string $slug = 'dev-activity';

    protected string $view = 'filament.pages.dev-activity.index';

    protected ?string $subheading = 'commit 數不含 merge；行數已排除 lock 檔與產生的檔案，僅供參考。「解了什麼」看 Redmine 議題：commit 引用的，加上每人在 Redmine 的工時、送驗與結案（依「開發者」設定的 Redmine 名稱對應）。';

    #[Url]
    public string $period = DevActivityReport::DEFAULT_PERIOD;

    #[Url]
    public ?string $person = null;

    public function mount(): void
    {
        if (! array_key_exists($this->period, DevActivityReport::PERIODS)) {
            $this->period = DevActivityReport::DEFAULT_PERIOD;
        }
    }

    public function setPeriod(string $period): void
    {
        $this->period = array_key_exists($period, DevActivityReport::PERIODS) ? $period : DevActivityReport::DEFAULT_PERIOD;
    }

    /**
     * Card click: filter to that person, or clear the filter when they are already selected.
     */
    public function togglePerson(string $key): void
    {
        $this->person = $this->person === $key ? null : $key;
    }

    public function updatedPerson(?string $value): void
    {
        $this->person = filled($value) ? $value : null;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        return DevActivityReport::periodRange($this->period);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('立即同步')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $run = app(GithubSync::class)->sync();

                    if ($run->status === SyncStatus::Ok) {
                        $stats = $run->stats ?? [];

                        Notification::make()
                            ->title('已同步')
                            ->body(sprintf(
                                '%d 個 repo，新增 %d 筆 commit、更新 %d 筆，PR %d 筆，review %d 筆',
                                $stats['repos'] ?? 0,
                                $stats['commits_created'] ?? 0,
                                $stats['commits_updated'] ?? 0,
                                $stats['prs'] ?? 0,
                                $stats['reviews'] ?? 0,
                            ))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()->title('同步失敗')->body($run->error)->danger()->persistent()->send();
                    }

                    $this->redirect(static::getUrl(['period' => $this->period, 'person' => $this->person]));
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = app(DevActivityReport::class);

        if (! $report->hasAnyData()) {
            return ['hasData' => false, 'periods' => DevActivityReport::PERIODS];
        }

        [$from, $to] = $this->range();
        $heatmapFrom = in_array($this->period, ['today', 'yesterday'], true)
            ? $to->startOfDay()->subDays(self::SHORT_PERIOD_HEATMAP_DAYS - 1)
            : $from;
        $personOptions = $report->personOptions();

        if ($this->person !== null && ! array_key_exists($this->person, $personOptions)) {
            $this->person = null;
        }

        return [
            'hasData' => true,
            'analysis' => Report::query()->where('type', ReportType::DevReview)->latest('period_start')->latest('id')->first(),
            'periods' => DevActivityReport::PERIODS,
            'from' => $from,
            'to' => $to,
            'personOptions' => $personOptions,
            'people' => $report->people($from, $to),
            'redmineTrackedSince' => ($trackedSince = app(RedmineActivity::class)->statusTrackedSince()) !== null && $trackedSince->gt($from) ? $trackedSince : null,
            'heatmap' => $report->heatmap($heatmapFrom, $to, $this->person),
            'heatmapIsTrailing' => $heatmapFrom->notEqualTo($from),
            'log' => $report->dailyLog($from, $to, $this->person),
            'untracked' => $report->untracked($from, $to, $this->person),
        ];
    }
}
