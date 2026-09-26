<?php

namespace App\Filament\Resources\Insights\Pages;

use App\Filament\Resources\Insights\Actions\InsightActions;
use App\Filament\Resources\Insights\InsightResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInsight extends ViewRecord
{
    protected static string $resource = InsightResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...InsightActions::all(),
            EditAction::make(),
        ];
    }
}
