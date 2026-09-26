<?php

namespace App\Filament\Resources\CostBaselines\Pages;

use App\Filament\Resources\CostBaselines\CostBaselineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCostBaseline extends EditRecord
{
    protected static string $resource = CostBaselineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
