<?php

namespace App\Filament\Resources\Developers\Pages;

use App\Filament\Resources\Developers\DeveloperResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeveloper extends CreateRecord
{
    protected static string $resource = DeveloperResource::class;
}
