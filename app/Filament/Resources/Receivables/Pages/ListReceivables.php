<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
