<?php

namespace App\Filament\Resources\AlertRules;

use App\Filament\NavigationGroup;
use App\Filament\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Resources\AlertRules\Tables\AlertRulesTable;
use App\Models\AlertRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = '警示規則';

    protected static ?string $pluralModelLabel = '警示規則';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AlertRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertRules::route('/'),
            'create' => CreateAlertRule::route('/create'),
            'edit' => EditAlertRule::route('/{record}/edit'),
        ];
    }
}
