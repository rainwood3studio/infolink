<?php

namespace App\Filament\Pages;

use App\Domain\Delivery\AcceptanceQueue as AcceptanceQueueReadModel;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\RedmineIssues\RedmineIssueResource;
use App\Models\RedmineIssue;
use App\Models\SyncRun;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 驗收隊列: the 驗證中 issues waiting for the single acceptor — how many and how old, how fast they are accepted
 * versus handed over, what to accept first (closing projects, then the longest waiting) and the 驗證中 issues
 * that sit with someone else. Data comes from the AcceptanceQueue read model.
 */
class AcceptanceQueue extends Page
{
    /** The suggested-order list shows at most this many issues; the issue mirror has the rest. */
    public const int MAX_QUEUE_ROWS = 100;

    /** Issues listed per assignee in the off-flow section. */
    public const int MAX_OFF_FLOW_ROWS = 15;

    public const int FLOW_WEEKS = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Delivery;

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = '驗收隊列';

    protected static ?string $title = '驗收隊列';

    protected static ?string $slug = 'acceptance';

    protected string $view = 'filament.pages.acceptance.index';

    /** Redmine project identifier the suggested-order list is filtered to. */
    #[Url]
    public ?string $project = null;

    public function updatedProject(?string $value): void
    {
        $this->project = filled($value) ? $value : null;
    }

    public function getSubheading(): string
    {
        $acceptor = (string) config('services.redmine.acceptor_name');

        return "所有議題的最終驗收都由{$acceptor}一個人做，所以「驗證中」且指派給他的議題就是驗收隊列。「流程外」是驗證中卻掛在別人名下（或沒指派）的議題：沒有人會去驗收。";
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $acceptance = app(AcceptanceQueueReadModel::class);
        $summary = $acceptance->summary();

        $projectOptions = collect($summary['by_project'])
            ->mapWithKeys(fn (array $project): array => [$project['identifier'] => "{$project['name']}（{$project['count']}）"])
            ->all();

        if ($this->project !== null && ! array_key_exists($this->project, $projectOptions)) {
            $this->project = null;
        }

        $queue = $acceptance->queue($this->project);
        $lastSync = SyncRun::latestFor(SyncJob::RedmineIssues, SyncStatus::Ok);

        return [
            'hasData' => $summary['queue'] + $summary['off_flow']['total'] > 0 || RedmineIssue::query()->exists(),
            'acceptor' => $summary['acceptor'],
            'summary' => $summary,
            'flow' => $acceptance->flow(self::FLOW_WEEKS),
            'projectOptions' => $projectOptions,
            'queue' => $queue->take(self::MAX_QUEUE_ROWS),
            'queueTotal' => $queue->count(),
            'offFlow' => $acceptance->offFlow(),
            'syncedAt' => $lastSync?->finished_at ?? $lastSync?->started_at,
            'verifyingUrl' => RedmineIssueResource::getUrl('index', ['filters' => array_filter([
                'status' => ['values' => [RedmineIssue::STATUS_VERIFYING]],
                'project_identifier' => $this->project === null ? null : ['values' => [$this->project]],
            ])]),
            'offFlowUrl' => RedmineIssueResource::getUrl('index', ['filters' => ['verifying_others' => ['isActive' => true]]]),
        ];
    }
}
