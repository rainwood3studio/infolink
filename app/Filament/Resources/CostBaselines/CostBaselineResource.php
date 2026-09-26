<?php

namespace App\Filament\Resources\CostBaselines;

use App\Filament\NavigationGroup;
use App\Filament\Resources\CostBaselines\Pages\CreateCostBaseline;
use App\Filament\Resources\CostBaselines\Pages\EditCostBaseline;
use App\Filament\Resources\CostBaselines\Pages\ListCostBaselines;
use App\Filament\Resources\CostBaselines\Schemas\CostBaselineForm;
use App\Filament\Resources\CostBaselines\Tables\CostBaselinesTable;
use App\Models\CostBaseline;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CostBaselineResource extends Resource
{
    protected static ?string $model = CostBaseline::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = '月成本基準';

    protected static ?string $pluralModelLabel = '月成本基準';

    public static function form(Schema $schema): Schema
    {
        return CostBaselineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostBaselinesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostBaselines::route('/'),
            'create' => CreateCostBaseline::route('/create'),
            'edit' => EditCostBaseline::route('/{record}/edit'),
        ];
    }
}
