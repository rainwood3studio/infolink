<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectStatus;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('專案')
                    ->schema(self::fields())
                    ->columns(2),
                SourceFields::section(),
            ]);
    }

    /**
     * Shared with the customer's projects relation manager, which sets `customer_id` itself.
     *
     * @return array<int, Component>
     */
    public static function fields(bool $withCustomer = true): array
    {
        return array_values(array_filter([
            $withCustomer
                ? Select::make('customer_id')
                    ->label('客戶')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                : null,
            TextInput::make('name')
                ->label('專案名稱')
                ->required()
                ->maxLength(255),
            Money::input(TextInput::make('contract_amount_untaxed'))
                ->label('合約金額（未稅）')
                ->minValue(0),
            Select::make('status')
                ->label('狀態')
                ->options(ProjectStatus::class)
                ->default(ProjectStatus::Active)
                ->required(),
            DatePicker::make('target_close_date')
                ->label('目標結案日'),
            TextInput::make('redmine_project_id')
                ->label('Redmine 專案 ID')
                ->integer()
                ->minValue(1),
        ]));
    }
}
