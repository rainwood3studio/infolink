<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label('看板')
                ->icon(Heroicon::OutlinedViewColumns)
                ->color('gray')
                ->url(DealResource::getUrl('board')),
            CreateAction::make(),
        ];
    }
}
