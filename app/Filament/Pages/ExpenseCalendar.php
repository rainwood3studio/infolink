<?php

namespace App\Filament\Pages;

use App\Domain\Finance\ExpenseCalendar as ExpenseCalendarReadModel;
use App\Filament\NavigationGroup;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * 支出日曆: the next months' dated outflows grouped by month — every recurring fixed expense occurrence and every
 * planned one-off outflow — with the extra not covered by the monthly cost baseline. Data comes from the
 * ExpenseCalendar read model; the page only renders it.
 */
class ExpenseCalendar extends Page
{
    public const int MONTHS = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 18;

    protected static ?string $navigationLabel = '支出日曆';

    protected static ?string $title = '支出日曆';

    protected static ?string $slug = 'expense-calendar';

    protected string $view = 'filament.pages.expense-calendar.index';

    protected ?string $subheading = '接下來 6 個月有日期的支出：固定支出（營業稅、勞健保、營所稅、獎金、租金…）每次到期各一列，加上已排定的一次性支出。'
        .'標「已含月成本」的已經算在月成本基準裡，只是提醒日期；其他的是月成本之外要另外準備的錢，推估會在到期日扣掉。';

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('manage')
                ->label('管理固定支出')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('gray')
                ->url(RecurringExpenseResource::getUrl('index')),
            Action::make('create')
                ->label('新增固定支出')
                ->icon(Heroicon::OutlinedPlus)
                ->url(RecurringExpenseResource::getUrl('create')),
        ];
    }

    /**
     * `2026-11` → `2026 年 11 月`.
     */
    public static function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('Y 年 n 月');
    }

    /**
     * `2026-11-15` → `11/15（六）`.
     */
    public static function dateLabel(string $date): string
    {
        $parsed = CarbonImmutable::parse($date);

        return $parsed->format('n/j').'（'.['日', '一', '二', '三', '四', '五', '六'][$parsed->dayOfWeek].'）';
    }

    public static function money(int $amount): string
    {
        return Money::format($amount);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'calendar' => app(ExpenseCalendarReadModel::class)->calculate(months: self::MONTHS),
            'createUrl' => RecurringExpenseResource::getUrl('create'),
            'editUrl' => fn (int $id): string => RecurringExpenseResource::getUrl('edit', ['record' => $id]),
        ];
    }
}
