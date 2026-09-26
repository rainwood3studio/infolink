<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Domain\Sales\DealService;
use App\Filament\Resources\Deals\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DealActions::advanceStage()
                ->visible(fn (Deal $record): bool => ! $record->stage->isClosed())
                ->after(fn () => $this->refreshFormData(['stage', 'probability', 'closed_at'])),
            DealActions::logInteraction()
                ->after(fn () => $this->refreshFormData(['next_action', 'next_action_on'])),
            DeleteAction::make(),
        ];
    }

    /**
     * Saves through DealService so a stage change made in the form is logged as a stage_change event.
     *
     * @param  Deal  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(DealService::class)->upsert([...$data, 'id' => $record->getKey()]);
    }
}
