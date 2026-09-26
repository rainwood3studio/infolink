<?php

namespace App\Filament\Widgets\CashFlow;

class LargestInflowsTable extends LargestLinesTable
{
    protected function side(): string
    {
        return 'deposit';
    }

    protected function tableHeading(): string
    {
        return '前五大流入';
    }

    protected function tableDescription(): string
    {
        return '期間內單筆入帳最大的 5 筆；看營收是否靠少數大額撐起。';
    }
}
