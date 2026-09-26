<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * 總覽: finance cards, 今天要處理, then the forecast and receivable charts side by side.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = '總覽';

    protected static ?string $navigationLabel = '總覽';

    /**
     * @return int|array<string, int>
     */
    public function getColumns(): int|array
    {
        return ['md' => 2];
    }
}
