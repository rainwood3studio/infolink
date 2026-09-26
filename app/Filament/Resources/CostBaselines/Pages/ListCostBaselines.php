<?php

namespace App\Filament\Resources\CostBaselines\Pages;

use App\Filament\Resources\CostBaselines\CostBaselineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCostBaselines extends ListRecords
{
    protected static string $resource = CostBaselineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
