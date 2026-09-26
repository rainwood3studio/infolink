<?php

namespace App\Filament\Resources\ActionItems\Schemas;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Filament\Resources\ActionItems\ActionItemResource;
use App\Filament\Support\SourceFields;
use App\Models\ActionItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ActionItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('待辦')
                    ->schema([
                        TextInput::make('title')
                            ->label('標題')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('detail')
                            ->label('說明')
                            ->rows(4)
                            ->columnSpanFull(),
                        Select::make('priority')
                            ->label('優先度')
                            ->options(ActionItemPriority::class)
                            ->default(ActionItemPriority::P2)
                            ->required(),
                        Select::make('status')
                            ->label('狀態')
                            ->options(ActionItemStatus::class)
                            ->default(ActionItemStatus::Todo)
                            ->required(),
                        DatePicker::make('due_on')
                            ->label('到期日'),
                        TextInput::make('owner')
                            ->label('負責人')
                            ->maxLength(255),
                        TextEntry::make('related')
                            ->label('關聯')
                            ->state(fn (?ActionItem $record): ?string => ActionItemResource::relatedLabel($record))
                            ->hiddenOn('create'),
                        TextEntry::make('completed_at')
                            ->label('完成時間')
                            ->dateTime()
                            ->placeholder('—')
                            ->hiddenOn('create'),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }
}
