<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Sidebar groups, in display order. Ungrouped items (總覽, 今天要處理) render above all groups.
 */
enum NavigationGroup implements HasLabel
{
    case Work;
    case Finance;
    case Sales;
    case Delivery;
    case Ops;
    case Reports;
    case Settings;

    public function getLabel(): string
    {
        return match ($this) {
            self::Work => '工作',
            self::Finance => '財務',
            self::Sales => '業務',
            self::Delivery => '交付',
            self::Ops => '維運',
            self::Reports => '報告',
            self::Settings => '設定',
        };
    }
}
