<?php

namespace App\Filament\Resources\Receivables\Actions;

use App\Domain\Finance\ReceivableService;
use App\Enums\ReceivableStatus;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;

/**
 * Status transitions for receivables. Usable as table row actions or page header actions.
 */
class ReceivableActions
{
    public static function markInvoiced(): Action
    {
        return Action::make('markInvoiced')
            ->label('標記已開票')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('info')
            ->visible(fn (Receivable $record): bool => $record->status === ReceivableStatus::Planned)
            ->modalHeading(fn (Receivable $record): string => "標記已開票：{$record->item}")
            ->schema([
                DatePicker::make('invoiced_on')
                    ->label('開票日')
                    ->default(today())
                    ->required(),
            ])
            ->action(function (Receivable $record, array $data, Action $action): void {
                app(ReceivableService::class)->markInvoiced($record, CarbonImmutable::parse($data['invoiced_on']));

                $action->success();
            })
            ->successNotificationTitle('已標記開票');
    }

    public static function markReceived(): Action
    {
        return Action::make('markReceived')
            ->label('標記已收')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Receivable $record): bool => in_array($record->status, [ReceivableStatus::Planned, ReceivableStatus::Invoiced], true))
            ->modalHeading(fn (Receivable $record): string => "標記已收：{$record->item}")
            ->schema([
                DatePicker::make('received_on')
                    ->label('收款日')
                    ->default(today())
                    ->required(),
            ])
            ->action(function (Receivable $record, array $data, Action $action): void {
                app(ReceivableService::class)->markReceived($record, CarbonImmutable::parse($data['received_on']));

                $action->success();
            })
            ->successNotificationTitle('已標記收款');
    }
}
