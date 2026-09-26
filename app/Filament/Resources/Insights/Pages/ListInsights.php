<?php

namespace App\Filament\Resources\Insights\Pages;

use App\Filament\Resources\Insights\InsightResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInsights extends ListRecords
{
    protected static string $resource = InsightResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
