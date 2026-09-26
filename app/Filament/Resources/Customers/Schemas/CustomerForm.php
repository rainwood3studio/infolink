<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerStatus;
use App\Filament\Support\SourceFields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('客戶')
                    ->schema([
                        TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_name')
                            ->label('簡稱')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->label('狀態')
                            ->options(CustomerStatus::class)
                            ->default(CustomerStatus::Active)
                            ->required(),
                        TagsInput::make('redmine_project_ids')
                            ->label('Redmine 專案 ID')
                            ->placeholder('輸入 ID 後按 Enter')
                            ->nestedRecursiveRules(['integer', 'min:1'])
                            ->dehydrateStateUsing(fn (?array $state): ?array => blank($state) ? null : array_values(array_map(intval(...), $state))),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }
}
