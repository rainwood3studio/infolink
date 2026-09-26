<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\Actions\ReceivableActions;
use App\Filament\Resources\Receivables\ReceivableResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReceivable extends EditRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReceivableActions::markInvoiced()->after(fn () => $this->refreshFormData(['status', 'invoiced_on'])),
            ReceivableActions::markReceived()->after(fn () => $this->refreshFormData(['status', 'received_on'])),
            DeleteAction::make(),
        ];
    }
}
