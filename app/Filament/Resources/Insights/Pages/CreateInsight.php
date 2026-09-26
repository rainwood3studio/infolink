<?php

namespace App\Filament\Resources\Insights\Pages;

use App\Filament\Resources\Insights\InsightResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateInsight extends CreateRecord
{
    protected static string $resource = InsightResource::class;

    /**
     * A hand-written insight gets a unique fingerprint so it never merges with rule/Claude-raised ones.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'fingerprint' => 'manual:'.Str::uuid(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
