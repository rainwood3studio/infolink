<?php

namespace App\Filament\Resources\MetricDefinitions\Pages;

use App\Filament\Resources\MetricDefinitions\MetricDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMetricDefinitions extends ListRecords
{
    protected static string $resource = MetricDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
