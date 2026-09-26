<?php

namespace App\Filament\Resources\Insights\Schemas;

use App\Filament\Support\SourceFields;
use App\Models\Insight;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InsightInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('title')
                            ->label('標題')
                            ->size('lg')
                            ->weight('bold')
                            ->columnSpanFull(),
                        TextEntry::make('severity')->label('嚴重度')->badge(),
                        TextEntry::make('kind')->label('類型')->badge(),
                        TextEntry::make('category')->label('分類')->badge(),
                        TextEntry::make('status')->label('狀態')->badge(),
                        TextEntry::make('body')
                            ->label('內容')
                            ->markdown()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('evidence')
                            ->label('證據')
                            ->state(fn (Insight $record): ?string => blank($record->evidence)
                                ? null
                                : json_encode($record->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                            ->fontFamily('mono')
                            ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('時間與來源')
                    ->schema([
                        TextEntry::make('first_seen_at')->label('首次發現')->dateTime(),
                        TextEntry::make('last_seen_at')->label('最近發現')->dateTime(),
                        TextEntry::make('resolved_at')->label('結案時間')->dateTime()->placeholder('—'),
                        TextEntry::make('expires_at')->label('到期自動結案')->dateTime()->placeholder('—'),
                        TextEntry::make('source')
                            ->label('來源')
                            ->formatStateUsing(fn ($state): ?string => SourceFields::label($state))
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('actor')->label('寫入者'),
                        TextEntry::make('fingerprint')->label('指紋'),
                        TextEntry::make('notes')->label('備註')->placeholder('—'),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }
}
