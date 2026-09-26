<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kinds of narrative reports Claude (or a person) saves into the app.
 */
enum ReportType: string implements HasColor, HasLabel
{
    case DailyBrief = 'daily_brief';
    case WeeklyRedmine = 'weekly_redmine';
    case WeeklyCompany = 'weekly_company';
    case MonthlyFinance = 'monthly_finance';
    case Adhoc = 'adhoc';

    public function getLabel(): string
    {
        return match ($this) {
            self::DailyBrief => '每日簡報',
            self::WeeklyRedmine => 'Redmine 週報',
            self::WeeklyCompany => '公司週回顧',
            self::MonthlyFinance => '月結財務',
            self::Adhoc => '臨時分析',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DailyBrief => 'info',
            self::WeeklyRedmine => 'warning',
            self::WeeklyCompany => 'success',
            self::MonthlyFinance => 'primary',
            self::Adhoc => 'gray',
        };
    }
}
