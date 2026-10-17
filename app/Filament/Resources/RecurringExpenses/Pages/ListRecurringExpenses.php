<?php

namespace App\Filament\Resources\RecurringExpenses\Pages;

use App\Filament\Pages\ExpenseCalendar;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRecurringExpenses extends ListRecords
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('支出日曆')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(ExpenseCalendar::getUrl()),
            CreateAction::make(),
        ];
    }
}
