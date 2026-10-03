<?php

namespace App\Filament\Pages;

use App\Domain\Sales\ProjectPnl as ProjectPnlReadModel;
use App\Filament\NavigationGroup;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 專案損益: where the team's time went in the period (person-days from GitHub commits) per customer and project, next
 * to the money each one brings in — plus the effort on deals that have no contract yet and on repos tied to nothing.
 * Data comes from the ProjectPnl read model; the page only picks the period.
 */
class ProjectPnl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = '專案損益';

    protected static ?string $title = '專案損益';

    protected static ?string $slug = 'project-pnl';

    protected string $view = 'filament.pages.project-pnl.index';

    protected ?string $subheading = '投入以「人天」計：一個人某天有 commit（不含 merge）算一個人天，當天碰了幾個專案就平分。估算成本是把整個月成本依人天比例攤到各專案，只是估算，不是實際成本。GitHub 只保留近一個月的 commit，所以最多看近 30 天。';

    #[Url]
    public string $period = ProjectPnlReadModel::DEFAULT_PERIOD;

    public function mount(): void
    {
        $this->period = self::allowedPeriod($this->period);
    }

    public function setPeriod(string $period): void
    {
        $this->period = self::allowedPeriod($period);
    }

    /**
     * Person-days with one decimal, e.g. `12.5`.
     */
    public static function days(float $days): string
    {
        return number_format($days, 1);
    }

    /**
     * A 0–1 share as a percentage, e.g. `57.6%`; `—` when unknown.
     */
    public static function percent(?float $share): string
    {
        return $share === null ? '—' : rtrim(rtrim(number_format($share * 100, 1), '0'), '.').'%';
    }

    /**
     * NT$ with the minus sign in front (and a plus sign when `$signed`); `—` when unknown.
     */
    public static function money(?int $amount, bool $signed = false): string
    {
        if ($amount === null) {
            return '—';
        }

        $sign = match (true) {
            $amount < 0 => '−',
            $signed && $amount > 0 => '+',
            default => '',
        };

        return $sign.Money::format(abs($amount));
    }

    /**
     * 「永彬 3.5、Kenneth 1.0」 for a row's `by_person`.
     *
     * @param  list<array{name: string, person_days: float, commits: int}>  $people
     */
    public static function peopleLine(array $people): string
    {
        return implode('、', array_map(fn (array $person): string => $person['name'].' '.self::days($person['person_days']), $people));
    }

    protected static function allowedPeriod(string $period): string
    {
        return array_key_exists($period, ProjectPnlReadModel::PERIODS) ? $period : ProjectPnlReadModel::DEFAULT_PERIOD;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->period = self::allowedPeriod($this->period);
        [$from, $to] = ProjectPnlReadModel::periodRange($this->period);

        return [
            'pnl' => app(ProjectPnlReadModel::class)->calculate($from, $to),
            'periods' => ProjectPnlReadModel::PERIODS,
            'from' => $from,
            'to' => $to,
        ];
    }
}
