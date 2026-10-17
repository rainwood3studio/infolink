<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Sales\CustomerOverview;
use App\Filament\Pages\CustomerCollections;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 客戶總覽: one page per customer with its maintenance contract, projects, open receivables, bank deposits, deals and
 * open Redmine issues. Data comes from the CustomerOverview read model; the page only renders it.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.resources.customers.overview';

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->short_name.' 總覽';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('編輯'),
            Action::make('collections')
                ->label('客戶收款')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->url(CustomerCollections::getUrl()),
        ];
    }

    /**
     * `NT$1,234,567`; `—` when unknown.
     */
    public static function money(?int $amount): string
    {
        return $amount === null ? '—' : Money::format($amount);
    }

    /**
     * `2026-03-27` → `2026/03/27`.
     */
    public static function dateLabel(?string $date): string
    {
        return $date === null ? '—' : CarbonImmutable::parse($date)->format('Y/m/d');
    }

    /**
     * `2026-03` → `2026/03`.
     */
    public static function monthLabel(string $month): string
    {
        return str_replace('-', '/', $month);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'overview' => app(CustomerOverview::class)->calculate($this->getRecord()),
        ];
    }
}
