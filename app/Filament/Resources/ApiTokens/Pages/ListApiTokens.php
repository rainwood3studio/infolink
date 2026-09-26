<?php

namespace App\Filament\Resources\ApiTokens\Pages;

use App\Filament\Resources\ApiTokens\Actions\ApiTokenActions;
use App\Filament\Resources\ApiTokens\ApiTokenResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApiTokenActions::create(),
        ];
    }

    /**
     * Mounted by the create action right after issuing a token; never shown as a button.
     */
    public function showTokenAction(): Action
    {
        return ApiTokenActions::showToken();
    }
}
