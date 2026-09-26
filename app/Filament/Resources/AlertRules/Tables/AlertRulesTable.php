<?php

namespace App\Filament\Resources\AlertRules\Tables;

use App\Domain\Alerts\Rules\Rule;
use App\Enums\Category;
use App\Filament\Resources\AlertRules\Actions\EvaluateRuleAction;
use App\Filament\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Models\AlertRule;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AlertRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('名稱')
                    ->description(fn (AlertRule $record): string => $record->key)
                    ->searchable(['name', 'key']),
                TextColumn::make('category')
                    ->label('分類')
                    ->badge()
                    ->sortable(),
                TextColumn::make('severity')
                    ->label('嚴重度')
                    ->badge(),
                TextColumn::make('condition')
                    ->label('條件')
                    ->state(fn (AlertRule $record): string => self::condition($record))
                    ->wrap(),
                TextColumn::make('fingerprint_template')
                    ->label('fingerprint')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')
                    ->label('啟用'),
                TextColumn::make('last_evaluated_at')
                    ->label('上次評估')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i:s')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->filters([
                SelectFilter::make('category')
                    ->label('分類')
                    ->options(Category::class),
                TernaryFilter::make('is_active')
                    ->label('啟用'),
            ])
            ->recordActions([
                EvaluateRuleAction::make(),
                EditAction::make(),
            ]);
    }

    /**
     * 「cash.runway_months < 3（嚴重 < 2）」 or 「應收逾期 > 3（嚴重 > 30）」.
     */
    public static function condition(AlertRule $record): string
    {
        $subject = filled($record->query_class) && is_subclass_of($record->query_class, Rule::class)
            ? $record->query_class::label()
            : ($record->metric_key ?? '—');

        $operator = AlertRuleForm::OPERATORS[$record->operator ?? ''] ?? ($record->operator ?? '');
        $format = fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');

        return $subject
            .($record->threshold !== null ? " {$operator} {$format($record->threshold)}" : '')
            .($record->critical_threshold !== null ? "（嚴重 {$operator} {$format($record->critical_threshold)}）" : '');
    }
}
