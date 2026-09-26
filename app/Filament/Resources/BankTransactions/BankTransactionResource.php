<?php

namespace App\Filament\Resources\BankTransactions;

use App\Filament\NavigationGroup;
use App\Filament\Resources\BankTransactions\Pages\CreateBankTransaction;
use App\Filament\Resources\BankTransactions\Pages\EditBankTransaction;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\BankTransactions\Schemas\BankTransactionForm;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = '銀行交易';

    protected static ?string $pluralModelLabel = '銀行交易';

    protected static ?string $recordTitleAttribute = 'summary';

    public static function form(Schema $schema): Schema
    {
        return BankTransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankTransactions::route('/'),
            'create' => CreateBankTransaction::route('/create'),
            'edit' => EditBankTransaction::route('/{record}/edit'),
        ];
    }
}
