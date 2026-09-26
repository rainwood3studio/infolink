<?php

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Domain\Sales\DealService;
use App\Enums\DealEventType;
use App\Filament\Resources\Deals\Actions\DealActions;
use App\Filament\Support\SourceFields;
use App\Models\Deal;
use App\Models\DealEvent;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 互動紀錄: newest first. New entries go through DealService::logEvent; stage changes appear here automatically.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = '互動紀錄';

    protected static ?string $modelLabel = '互動';

    protected static ?string $pluralModelLabel = '互動';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('類型')
                    ->options(DealActions::interactionTypes())
                    ->default(DealEventType::Meeting->value)
                    ->required(),
                DatePicker::make('occurred_on')
                    ->label('日期')
                    ->default(today())
                    ->required(),
                Textarea::make('content')
                    ->label('內容')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->columns([
                TextColumn::make('occurred_on')
                    ->label('日期')
                    ->date(),
                TextColumn::make('type')
                    ->label('類型')
                    ->badge(),
                TextColumn::make('content')
                    ->label('內容')
                    ->description(fn (DealEvent $record): ?string => $record->from_stage !== null || $record->to_stage !== null
                        ? ($record->from_stage?->getLabel() ?? '—').' → '.($record->to_stage?->getLabel() ?? '—')
                        : null)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('actor')
                    ->label('寫入者')
                    ->toggleable(),
                SourceFields::column(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('記錄互動')
                    ->using(function (array $data): Model {
                        /** @var Deal $deal */
                        $deal = $this->getOwnerRecord();

                        return app(DealService::class)->logEvent(
                            $deal,
                            DealEventType::from($data['type']),
                            $data['content'],
                            CarbonImmutable::parse($data['occurred_on']),
                        );
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
