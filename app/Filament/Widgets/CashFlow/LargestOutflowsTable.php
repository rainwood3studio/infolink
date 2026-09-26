<?php

namespace App\Filament\Widgets\CashFlow;

class LargestOutflowsTable extends LargestLinesTable
{
    protected function side(): string
    {
        return 'withdrawal';
    }

    protected function tableHeading(): string
    {
        return '前五大流出';
    }

    protected function tableDescription(): string
    {
        return '期間內單筆支出最大的 5 筆；一次性的大額支出會在這裡現形。';
    }
}
