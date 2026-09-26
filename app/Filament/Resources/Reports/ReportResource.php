<?php

namespace App\Filament\Resources\Reports;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\Schemas\ReportInfolist;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Models\Report;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only archive of the Markdown reports Claude saves through MCP (`save_report`).
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Reports;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = '報告';

    protected static ?string $modelLabel = '報告';

    protected static ?string $pluralModelLabel = '報告';

    protected static ?string $recordTitleAttribute = 'title';

    public static function infolist(Schema $schema): Schema
    {
        return ReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'view' => ViewReport::route('/{record}'),
        ];
    }

    /**
     * Report ids are integers; without this, /reports/create (no create page) reaches Postgres as a bigint and 500s.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (! ctype_digit((string) $key)) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }
}
