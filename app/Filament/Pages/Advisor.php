<?php

namespace App\Filament\Pages;

use App\Enums\ReportType;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Widgets\AttentionListWidget;
use App\Mcp\Prompts\CompanyAdvisor;
use App\Models\Report;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * AI 顧問: the newest `advisor` report (Claude's read of every area of the company: a traffic light and a headline
 * per area, the three most important things, then the full analysis), today's daily brief, links to the other
 * analyses, and the open insights / due action items underneath.
 */
class Advisor extends Page
{
    /** Hour by which a weekday's analysis is expected (the launchd job runs at 08:55). */
    public const int DUE_HOUR = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'AI 顧問';

    protected static ?string $title = 'AI 顧問';

    protected static ?string $slug = 'advisor';

    protected string $view = 'filament.pages.advisor.index';

    protected ?string $subheading = '每個工作日早上，Claude 把現金、收入、結案、驗收、團隊、營運六個面向的數字全部看過一遍，告訴你現況、要注意的地方和建議。燈號：紅＝本週要處理、黃＝兩週內要有動作、綠＝正常。';

    /**
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            AttentionListWidget::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = $this->latest(ReportType::Advisor);
        $advisor = $report?->metrics_snapshot['advisor'] ?? [];

        return [
            'report' => $report,
            'reportUrl' => $report ? ReportResource::getUrl('view', ['record' => $report]) : null,
            'isStale' => $this->isStale($report),
            'domains' => $this->domains(is_array($advisor) ? $advisor : []),
            'top' => collect(is_array($advisor) ? ($advisor['top'] ?? []) : [])->filter(fn (mixed $line): bool => is_string($line) && $line !== '')->values()->all(),
            'brief' => $this->latest(ReportType::DailyBrief),
            'others' => collect([ReportType::WeeklyCompany, ReportType::DevReview, ReportType::MonthlyFinance])
                ->map(fn (ReportType $type): ?Report => $this->latest($type))
                ->filter()
                ->values(),
        ];
    }

    protected function latest(ReportType $type): ?Report
    {
        return Report::query()->where('type', $type)->latest('period_start')->latest('id')->first();
    }

    /**
     * The newest report is older than expected: today's on a weekday past the due hour, else the last weekday's.
     */
    protected function isStale(?Report $report): bool
    {
        if ($report === null) {
            return false;
        }

        $expected = now()->isWeekday() && now()->hour >= self::DUE_HOUR
            ? today()
            : today()->subWeekday();

        return $report->period_start->lt($expected);
    }

    /**
     * The six areas in display order, with whatever the report said about each (unknown status → null).
     *
     * @param  array<string, mixed>  $advisor
     * @return list<array{key:string, name:string, status:?string, headline:?string}>
     */
    protected function domains(array $advisor): array
    {
        $byKey = collect($advisor['domains'] ?? [])
            ->filter(fn (mixed $domain): bool => is_array($domain) && isset($domain['key']))
            ->keyBy('key');

        return collect(CompanyAdvisor::DOMAINS)
            ->map(function (string $name, string $key) use ($byKey): array {
                $domain = $byKey->get($key, []);
                $status = $domain['status'] ?? null;

                return [
                    'key' => $key,
                    'name' => $name,
                    'status' => in_array($status, CompanyAdvisor::STATUSES, true) ? $status : null,
                    'headline' => is_string($domain['headline'] ?? null) ? $domain['headline'] : null,
                ];
            })
            ->values()
            ->all();
    }
}
