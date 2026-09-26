<?php

namespace App\Filament\Resources\Insights\Pages;

use App\Filament\Resources\Insights\InsightResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInsight extends EditRecord
{
    protected static string $resource = InsightResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
