<?php

namespace App\Filament\Resources\AlertRules\Actions;

use App\Domain\Alerts\RuleEvaluator;
use App\Models\AlertRule;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * 「立即評估」: run one active rule now (not a dry run) and report what it raised / resolved.
 */
class EvaluateRuleAction
{
    public static function make(): Action
    {
        return Action::make('evaluateNow')
            ->label('立即評估')
            ->icon(Heroicon::OutlinedPlay)
            ->color('gray')
            ->visible(fn (AlertRule $record): bool => $record->is_active)
            ->action(function (AlertRule $record): void {
                $result = app(RuleEvaluator::class)->evaluate($record->key);
                $outcome = $result->outcomes[0] ?? null;
                $stats = $result->stats();

                if ($outcome?->error !== null) {
                    Notification::make()->title('評估失敗')->body($outcome->error)->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($stats['fired'] > 0 ? "觸發 {$stats['fired']} 項" : '目前沒有觸發')
                    ->body(sprintf('新增 %d、更新 %d、自動結案 %d', $stats['raised'], $stats['updated'], $stats['resolved']))
                    ->success()
                    ->send();
            });
    }
}
