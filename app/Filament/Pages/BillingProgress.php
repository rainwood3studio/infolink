<?php

namespace App\Filament\Pages;

use App\Domain\Finance\BillingProgress as BillingProgressReadModel;
use App\Domain\Finance\DepositMatcher;
use App\Filament\NavigationGroup;
use App\Filament\Resources\BankTransactions\Actions\SettleDepositAction;
use App\Filament\Resources\Receivables\Actions\ReceivableActions;
use App\Filament\Support\Money;
use App\Models\BankTransaction;
use App\Models\Receivable;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * 請款進度: one row per project with a stacked bar from contract to money in the bank (已收 / 已開票未收 / 已排未開票 / 未排入),
 * the receivables behind it with 標記已開票 / 標記已收 at hand, the unsettled bank deposits with the matcher's suggestion
 * and a 沖銷 button, and a strip per maintenance customer showing which periods have their fee row, invoice and payment.
 * Data comes from the BillingProgress and DepositMatcher read models; the page only hosts the actions.
 */
class BillingProgress extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 16;

    protected static ?string $navigationLabel = '請款進度';

    protected static ?string $title = '請款進度';

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.pages.billing.index';

    protected ?string $subheading = '每個專案一條：合約金額拆成已收、已開票未收、已排未開票，剩下的是還沒排進應收的部分（金額都未稅，維運費另外看）。'
        .'預計收款日已過還沒收到算「逾期」；7 天內要收但發票還沒開算「到期未開票」。'
        .'「待沖銷入帳」是銀行明細裡類別為收入、還沒對到應收的入帳，依戶名找客戶、再比金額（含稅相符、未稅相符、兩三筆合計相符、差 1% 以內）給建議。';

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
     * A share of a base as a CSS percentage with two decimals, clamped to 0–100.
     */
    public static function width(int $amount, int $base): string
    {
        return $base <= 0 ? '0' : (string) round(max(0, min(100, $amount / $base * 100)), 2);
    }

    /**
     * A 0–1 share as `57%`; `—` when unknown.
     */
    public static function percent(?float $share): string
    {
        return $share === null ? '—' : round($share * 100).'%';
    }

    public function markInvoicedAction(): Action
    {
        return ReceivableActions::markInvoiced()->record(fn (array $arguments): ?Receivable => Receivable::query()->find($arguments['receivable'] ?? null));
    }

    public function markReceivedAction(): Action
    {
        return ReceivableActions::markReceived()->record(fn (array $arguments): ?Receivable => Receivable::query()->find($arguments['receivable'] ?? null));
    }

    public function settleBestAction(): Action
    {
        return SettleDepositAction::confirmBest()->record(fn (array $arguments): ?BankTransaction => BankTransaction::query()->find($arguments['transaction'] ?? null));
    }

    public function settleDepositAction(): Action
    {
        return SettleDepositAction::make()
            ->label('選擇應收')
            ->icon(Heroicon::OutlinedListBullet)
            ->color('gray')
            ->size('xs')
            ->record(fn (array $arguments): ?BankTransaction => BankTransaction::query()->find($arguments['transaction'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'billing' => app(BillingProgressReadModel::class)->calculate(),
            'deposits' => app(DepositMatcher::class)->suggest(),
        ];
    }
}
