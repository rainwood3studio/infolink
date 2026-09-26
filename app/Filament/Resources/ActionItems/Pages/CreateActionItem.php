<?php

namespace App\Filament\Resources\ActionItems\Pages;

use App\Domain\Work\ActionItemService;
use App\Filament\Resources\ActionItems\ActionItemResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateActionItem extends CreateRecord
{
    protected static string $resource = ActionItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ActionItemService::class)->create($data);
    }
}
