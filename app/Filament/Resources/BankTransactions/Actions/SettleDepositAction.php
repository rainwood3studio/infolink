<?php

namespace App\Filament\Resources\BankTransactions\Actions;

use App\Domain\Finance\DepositMatcher;
use App\Enums\TransactionCategory;
use App\Filament\Support\Money;
use App\Models\BankTransaction;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Settle a revenue deposit against outstanding receivables through {@see DepositMatcher::settle()}. Usable as a
 * bank-transaction table row action or, with `->record(fn (array $arguments) => …)`, as an action on a custom page.
 */
class SettleDepositAction
{
    /**
     * Pick the receivables by hand; the matcher's best suggestion is pre-selected and that customer's outstanding
     * receivables are offered first (every outstanding receivable when the counterparty matches no customer).
     */
    public static function make(string $name = 'settleDeposit'): Action
    {
        return Action::make($name)
            ->label('沖銷應收')
            ->icon(Heroicon::OutlinedLink)
            ->color('success')
            ->visible(fn (?BankTransaction $record): bool => $record !== null && self::isUnsettledDeposit($record))
            ->modalHeading(fn (BankTransaction $record): string => self::heading($record))
            ->modalDescription(function (BankTransaction $record): ?HtmlString {
                $suggestion = app(DepositMatcher::class)->suggestFor($record);

                if ($suggestion === null) {
                    return new HtmlString(e('對不到客戶（戶名「'.($record->counterparty ?: '—').'」）。下面列的是所有未收應收；要讓下次自動對上，到客戶資料加「銀行入帳戶名」。'));
                }

                $best = $suggestion['best'] ?? $suggestion['suggestions'][0] ?? null;

                return new HtmlString(e($best === null
                    ? "戶名對到 {$suggestion['customer']['short_name']}，但沒有金額相符的未收應收；請自行挑選。"
                    : "建議：{$best['label']}（{$best['kind_label']}，含稅 ".Money::format($best['amount_taxed']).($best['difference'] === 0 ? '' : '，差 '.Money::format($best['difference'])).'）。'));
            })
            ->schema(function (BankTransaction $record): array {
                $matcher = app(DepositMatcher::class);
                $suggestion = $matcher->suggestFor($record);

                return [
                    Select::make('receivable_ids')
                        ->label('沖銷的應收')
                        ->multiple()
                        ->searchable()
                        ->options($matcher->outstandingOptions($suggestion['customer']['id'] ?? null))
                        ->default($suggestion['best']['receivable_ids'] ?? [])
                        ->required()
                        ->helperText('一筆入帳可以同時沖銷幾筆應收（例如期中款＋尾款一起匯）。'),
                    DatePicker::make('received_on')
                        ->label('收款日')
                        ->default($record->txn_date)
                        ->required(),
                ];
            })
            ->action(function (BankTransaction $record, array $data, Action $action): void {
                self::settle($record, $data['receivable_ids'] ?? [], CarbonImmutable::parse($data['received_on']), $action);
            })
            ->successNotificationTitle('已沖銷');
    }

    /**
     * One-click settle with the matcher's best suggestion (exact or combination), after confirmation.
     */
    public static function confirmBest(string $name = 'settleBest'): Action
    {
        return Action::make($name)
            ->label('沖銷')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->size('xs')
            ->visible(fn (?BankTransaction $record): bool => $record !== null && self::isUnsettledDeposit($record) && app(DepositMatcher::class)->suggestFor($record)['best'] !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (BankTransaction $record): string => self::heading($record))
            ->modalDescription(function (BankTransaction $record): string {
                $best = app(DepositMatcher::class)->suggestFor($record)['best'];

                return "把這筆入帳沖銷到「{$best['label']}」（{$best['kind_label']}，含稅 ".Money::format($best['amount_taxed']).'），收款日 '.$record->txn_date->format('Y/m/d').'。';
            })
            ->modalSubmitActionLabel('確認沖銷')
            ->action(function (BankTransaction $record, Action $action): void {
                $best = app(DepositMatcher::class)->suggestFor($record)['best'] ?? null;

                self::settle($record, $best['receivable_ids'] ?? [], CarbonImmutable::instance($record->txn_date), $action);
            })
            ->successNotificationTitle('已沖銷');
    }

    public static function isUnsettledDeposit(BankTransaction $transaction): bool
    {
        return $transaction->category === TransactionCategory::Revenue && $transaction->deposit > 0 && $transaction->receivable_id === null;
    }

    protected static function heading(BankTransaction $record): string
    {
        return '沖銷應收：'.$record->txn_date->format('Y/m/d').' 入帳 '.Money::format($record->deposit).($record->counterparty ? '（'.$record->counterparty.'）' : '');
    }

    /**
     * @param  list<int|string>  $receivableIds
     */
    protected static function settle(BankTransaction $record, array $receivableIds, CarbonImmutable $receivedOn, Action $action): void
    {
        try {
            $settled = app(DepositMatcher::class)->settle($record, $receivableIds, $receivedOn);
        } catch (InvalidArgumentException $exception) {
            $action->failureNotificationTitle($exception->getMessage())->failure();
            $action->halt();

            return;
        }

        $action->successNotificationTitle('已沖銷 '.$settled->count().' 筆應收：'.$settled->pluck('item')->implode('、'))->success();
    }
}
