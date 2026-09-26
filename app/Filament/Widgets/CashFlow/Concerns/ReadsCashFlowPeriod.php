<?php

namespace App\Filament\Widgets\CashFlow\Concerns;

use App\Domain\Finance\CashFlowAnalytics;
use App\Filament\Pages\CashFlowAnalysis;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * 金流分析 widgets: the analytics for the period chosen in the page filters.
 */
trait ReadsCashFlowPeriod
{
    use InteractsWithPageFilters;

    /**
     * @var array{0: string, 1: CashFlowAnalytics}|null
     */
    protected ?array $cashFlowAnalytics = null;

    /**
     * Memoized per request and per filter state (reactive filters can change within a request).
     */
    protected function analytics(): CashFlowAnalytics
    {
        $key = md5((string) json_encode($this->pageFilters));

        if ($this->cashFlowAnalytics === null || $this->cashFlowAnalytics[0] !== $key) {
            $this->cashFlowAnalytics = [$key, CashFlowAnalysis::analyticsFor($this->pageFilters)];
        }

        return $this->cashFlowAnalytics[1];
    }
}
