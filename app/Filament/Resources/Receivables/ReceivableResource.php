<?php

namespace App\Filament\Resources\Receivables;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Receivables\Pages\CreateReceivable;
use App\Filament\Resources\Receivables\Pages\EditReceivable;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Schemas\ReceivableForm;
use App\Filament\Resources\Receivables\Tables\ReceivablesTable;
use App\Models\Receivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = '應收帳款';

    protected static ?string $pluralModelLabel = '應收帳款';

    protected static ?string $recordTitleAttribute = 'item';

    public static function form(Schema $schema): Schema
    {
        return ReceivableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReceivablesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
            'create' => CreateReceivable::route('/create'),
            'edit' => EditReceivable::route('/{record}/edit'),
        ];
    }
}
