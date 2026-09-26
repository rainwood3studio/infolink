<?php

namespace App\Filament\Resources\ActionItems\Pages;

use App\Domain\Work\ActionItemService;
use App\Filament\Resources\ActionItems\ActionItemResource;
use App\Filament\Resources\ActionItems\Actions\ActionItemActions;
use App\Models\ActionItem;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditActionItem extends EditRecord
{
    protected static string $resource = ActionItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionItemActions::complete()->after(fn () => $this->refreshFormData(['status', 'completed_at'])),
            ActionItemActions::reopen()->after(fn () => $this->refreshFormData(['status', 'completed_at'])),
            DeleteAction::make(),
        ];
    }

    /**
     * Saves through the service so status changes keep `completed_at` in sync.
     *
     * @param  ActionItem  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ActionItemService::class)->update($record, $data);
    }
}
