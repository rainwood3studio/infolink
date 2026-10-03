<?php

namespace App\Filament\Pages;

use App\Domain\Finance\RevenueOutlook as RevenueOutlookReadModel;
use App\Filament\NavigationGroup;
use App\Filament\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 收入展望: the next twelve months of cash in layers (booked receivables, recurring fees, the assumed renewal of
 * those fees, low-confidence receivables, the weighted pipeline) against the monthly cost — when cash starts
 * falling, when it reaches zero and how much new business closes the gap. Data comes from the RevenueOutlook read
 * model; the two controls only change the scenario, nothing is saved.
 */
class RevenueOutlook extends Page
{
    public const int MONTHS = 12;

    /** Months the 「尾款延後」 control offers. */
    public const array DELAY_OPTIONS = [0, 1, 2, 3, 6];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 16;

    protected static ?string $navigationLabel = '收入展望';

    protected static ?string $title = '收入展望';

    protected static ?string $slug = 'revenue-outlook';

    protected string $view = 'filament.pages.revenue-outlook.index';

    /** Every non-recurring high-confidence receivable is moved this many months later. */
    #[Url(as: 'delay')]
    public int $delayMonths = 0;

    /** Count low-confidence receivables in the base balance. */
    #[Url(as: 'low')]
    public bool $includeLowConfidence = false;

    public function updatedDelayMonths(): void
    {
        $this->delayMonths = self::allowedDelay($this->delayMonths);
    }

    public function getSubheading(): string
    {
        return '三條餘額線：「已確定」只算已登記的高確定性應收與經常性收入；「基準」再加上假設維運合約續約；「含業務機會」再加上加權後的業務機會（未稅）。'
            .'經常性收入只登記到最後一筆的月份，之後的月份是假設照同樣金額續約，不是已確定的收入。';
    }

    /**
     * `2026-11` → `2026 年 11 月`.
     */
    public static function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('Y 年 n 月');
    }

    /**
     * NT$ with the minus sign in front (and a plus sign when `$signed`), e.g. `−NT$109,666`.
     */
    public static function money(int $amount, bool $signed = false): string
    {
        $sign = match (true) {
            $amount < 0 => '−',
            $signed && $amount > 0 => '+',
            default => '',
        };

        return $sign.Money::format(abs($amount));
    }

    /**
     * Whole NTD in 萬 for axis ticks and chart labels, e.g. `250 萬`, `−2.5 萬`, `0`.
     */
    public static function wan(int|float $amount): string
    {
        if (round($amount / 1_000) == 0) {
            return '0';
        }

        $number = rtrim(rtrim(number_format(abs($amount) / 10_000, 1), '0'), '.');

        return ($amount < 0 ? '−' : '').$number.' 萬';
    }

    /**
     * A round tick step (1, 2, 2.5 or 5 × a power of ten) that splits the range into about `$ticks` parts.
     */
    public static function niceStep(int|float $range, int $ticks = 5): float
    {
        $raw = max(1, $range) / $ticks;
        $magnitude = 10 ** floor(log10($raw));

        foreach ([1, 2, 2.5, 5] as $multiple) {
            if ($raw <= $multiple * $magnitude) {
                return $multiple * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    protected static function allowedDelay(int $months): int
    {
        return in_array($months, self::DELAY_OPTIONS, true) ? $months : 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->delayMonths = self::allowedDelay($this->delayMonths);

        return [
            'outlook' => app(RevenueOutlookReadModel::class)->calculate(self::MONTHS, $this->delayMonths, $this->includeLowConfidence),
            'delayOptions' => self::DELAY_OPTIONS,
        ];
    }
}
