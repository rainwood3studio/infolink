<?php

namespace App\Filament\Resources\MetricDefinitions\Actions;

use App\Domain\Metrics\MetricRecorder;
use App\Enums\Source;
use App\Models\MetricDefinition;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * 「手動補值」: write one value for a non-calculated metric (calculator = null) through the MetricRecorder,
 * which normalises the period start and upserts on (metric, period, dimension).
 */
class RecordMetricValueAction
{
    /**
     * @param  (Closure(): MetricDefinition)|null  $resolveDefinition  Resolves the metric when the action has no record (relation manager header).
     */
    public static function make(?Closure $resolveDefinition = null): Action
    {
        $definition = fn (?MetricDefinition $record): MetricDefinition => $resolveDefinition !== null ? $resolveDefinition() : $record;

        return Action::make('recordValue')
            ->label('手動補值')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (?MetricDefinition $record): bool => $definition($record)->calculator === null)
            ->modalHeading(fn (?MetricDefinition $record): string => '手動補值：'.$definition($record)->name)
            ->modalDescription(fn (?MetricDefinition $record): string => '期間起點會依指標週期（'.$definition($record)->period_type->getLabel().'）自動對齊；同期間同維度會覆寫舊值。')
            ->schema(fn (?MetricDefinition $record): array => [
                DatePicker::make('period_start')
                    ->label('期間')
                    ->default(today())
                    ->required(),
                TextInput::make('value')
                    ->label('數值（'.$definition($record)->unit->getLabel().'）')
                    ->numeric()
                    ->required(),
                TextInput::make('dimension')
                    ->label('維度（選填）')
                    ->helperText('例如專案代號；留空代表整體')
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('備註')
                    ->rows(2),
            ])
            ->action(function (?MetricDefinition $record, array $data, Action $action) use ($definition): void {
                app(MetricRecorder::class)->record(
                    key: $definition($record)->key,
                    value: (float) $data['value'],
                    periodStart: CarbonImmutable::parse($data['period_start']),
                    dimension: (string) ($data['dimension'] ?? ''),
                    source: Source::Manual,
                    notes: filled($data['notes'] ?? null) ? $data['notes'] : null,
                );

                $action->success();
            })
            ->successNotificationTitle('已寫入數值');
    }
}
