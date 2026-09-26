<?php

namespace App\Filament\Resources\RedmineTimeEntries\Pages;

use App\Filament\Resources\RedmineTimeEntries\RedmineTimeEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListRedmineTimeEntries extends ListRecords
{
    protected static string $resource = RedmineTimeEntryResource::class;

    protected ?string $subheading = '唯讀鏡像。只有少數人登記工時，時數僅供參考方向。';
}
