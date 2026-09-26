<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Domain\Sales\DealService;
use App\Filament\Resources\Deals\DealResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    /**
     * Creates through DealService; refuses instead of silently updating when the same party already has a deal with this title.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $dealService = app(DealService::class);

        if ($dealService->findExisting($data) !== null) {
            Notification::make()->title('已有同一客戶、同標題的業務機會')->danger()->send();

            $this->halt();
        }

        return $dealService->upsert($data);
    }
}
