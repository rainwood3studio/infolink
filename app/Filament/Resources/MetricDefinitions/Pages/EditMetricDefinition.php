<?php

namespace App\Filament\Resources\MetricDefinitions\Pages;

use App\Filament\Resources\MetricDefinitions\MetricDefinitionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMetricDefinition extends EditRecord
{
    protected static string $resource = MetricDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
