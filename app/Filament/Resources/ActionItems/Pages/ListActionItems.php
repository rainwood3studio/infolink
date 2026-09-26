<?php

namespace App\Filament\Resources\ActionItems\Pages;

use App\Filament\Resources\ActionItems\ActionItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListActionItems extends ListRecords
{
    protected static string $resource = ActionItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
