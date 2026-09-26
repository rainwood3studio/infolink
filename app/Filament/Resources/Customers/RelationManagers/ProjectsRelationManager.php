<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $title = '專案';

    protected static ?string $modelLabel = '專案';

    protected static ?string $pluralModelLabel = '專案';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(ProjectForm::fields(withCustomer: false))
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return ProjectsTable::configure($table, withCustomer: false)
            ->recordTitleAttribute('name')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
