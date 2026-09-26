<?php

namespace App\Filament\Resources\Insights\Schemas;

use App\Enums\Category;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Filament\Support\SourceFields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InsightForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('注意事項')
                    ->schema([
                        TextInput::make('title')
                            ->label('標題')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('body')
                            ->label('內容')
                            ->rows(5)
                            ->columnSpanFull(),
                        Select::make('kind')
                            ->label('類型')
                            ->options(InsightKind::class)
                            ->default(InsightKind::Reminder)
                            ->required(),
                        Select::make('severity')
                            ->label('嚴重度')
                            ->options(InsightSeverity::class)
                            ->default(InsightSeverity::Info)
                            ->required(),
                        Select::make('category')
                            ->label('分類')
                            ->options(Category::class)
                            ->default(Category::Company)
                            ->required(),
                        Select::make('status')
                            ->label('狀態')
                            ->options(InsightStatus::class)
                            ->default(InsightStatus::Open)
                            ->required(),
                        DateTimePicker::make('expires_at')
                            ->label('到期自動結案')
                            ->seconds(false),
                        TextEntry::make('fingerprint')
                            ->label('指紋')
                            ->hiddenOn('create'),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }
}
