<?php

namespace App\Filament\Resources\RecurringExpenses;

use App\Filament\NavigationGroup;
use App\Filament\Resources\RecurringExpenses\Pages\CreateRecurringExpense;
use App\Filament\Resources\RecurringExpenses\Pages\EditRecurringExpense;
use App\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Filament\Resources\RecurringExpenses\Schemas\RecurringExpenseForm;
use App\Filament\Resources\RecurringExpenses\Tables\RecurringExpensesTable;
use App\Models\RecurringExpense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 固定支出: the dated obligations with their own rhythm (營業稅, 勞健保, 營所稅, bonuses, rent) that the 支出日曆 lists
 * and the cash forecast deducts unless they are already inside the cost baseline.
 */
class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 19;

    protected static ?string $modelLabel = '固定支出';

    protected static ?string $pluralModelLabel = '固定支出';

    protected static ?string $slug = 'recurring-expenses';

    public static function form(Schema $schema): Schema
    {
        return RecurringExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringExpensesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringExpenses::route('/'),
            'create' => CreateRecurringExpense::route('/create'),
            'edit' => EditRecurringExpense::route('/{record}/edit'),
        ];
    }
}
