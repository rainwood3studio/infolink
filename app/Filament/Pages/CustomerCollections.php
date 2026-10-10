<?php

namespace App\Filament\Pages;

use App\Domain\Finance\CustomerCollections as CustomerCollectionsReadModel;
use App\Filament\NavigationGroup;
use App\Filament\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 客戶收款: one card per customer with its bank deposits by month, and for customers with a maintenance contract a
 * strip of billing periods coloured by whether the fee arrived. Data comes from the CustomerCollections read model;
 * the page only picks the window.
 */
class CustomerCollections extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 17;

    protected static ?string $navigationLabel = '客戶收款';

    protected static ?string $title = '客戶收款';

    protected static ?string $slug = 'customer-collections';

    protected string $view = 'filament.pages.customer-collections.index';

    protected ?string $subheading = '每個客戶一張卡：銀行明細裡類別為「收入」的入帳，依月份排開。有維護合約的客戶再逐期對帳：一期收到的錢只要不少於未稅的維護費就算「已入帳」'
        .'（銀行不會說一筆匯款是付哪張發票，維護費和專案款一起匯的客戶只能這樣看）；下一期開始 5 天內匯進來的錢先補上一期。'
        .'入帳對不到客戶時，到客戶資料加「銀行入帳戶名」。';

    #[Url]
    public string $window = CustomerCollectionsReadModel::DEFAULT_WINDOW;

    public function mount(): void
    {
        $this->window = self::allowedWindow($this->window);
    }

    public function setWindow(string $window): void
    {
        $this->window = self::allowedWindow($window);
    }

    /**
     * `NT$1,234,567`; `—` when unknown.
     */
    public static function money(?int $amount): string
    {
        return $amount === null ? '—' : Money::format($amount);
    }

    /**
     * `2026-03` → `2026/03`.
     */
    public static function monthLabel(string $month): string
    {
        return str_replace('-', '/', $month);
    }

    /**
     * `2026-03-27` → `2026/03/27`.
     */
    public static function dateLabel(?string $date): string
    {
        return $date === null ? '—' : CarbonImmutable::parse($date)->format('Y/m/d');
    }

    protected static function allowedWindow(string $window): string
    {
        return array_key_exists($window, CustomerCollectionsReadModel::WINDOWS) ? $window : CustomerCollectionsReadModel::DEFAULT_WINDOW;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->window = self::allowedWindow($this->window);

        return [
            'collections' => app(CustomerCollectionsReadModel::class)->calculate($this->window),
            'windows' => CustomerCollectionsReadModel::WINDOWS,
        ];
    }
}
