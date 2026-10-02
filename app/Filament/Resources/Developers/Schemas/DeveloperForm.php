<?php

namespace App\Filament\Resources\Developers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeveloperForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('開發者')
                    ->schema([
                        TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('redmine_name')
                            ->label('Redmine 名稱')
                            ->helperText('Redmine 上顯示的名字，用來對照議題的被分派者。')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('在職')
                            ->helperText('離職的人沒有活動時不會出現在開發活動。')
                            ->default(true),
                        Textarea::make('notes')
                            ->label('備註')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
