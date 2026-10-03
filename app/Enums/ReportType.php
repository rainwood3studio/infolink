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
    case DevReview = 'dev_review';
    case Advisor = 'advisor';
    case Adhoc = 'adhoc';

    public function getLabel(): string
    {
        return match ($this) {
            self::DailyBrief => '每日簡報',
            self::WeeklyRedmine => 'Redmine 週報',
            self::WeeklyCompany => '公司週回顧',
            self::MonthlyFinance => '月結財務',
            self::DevReview => '開發活動分析',
            self::Advisor => 'AI 顧問分析',
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
            self::DevReview => 'info',
            self::Advisor => 'primary',
            self::Adhoc => 'gray',
        };
    }
}
