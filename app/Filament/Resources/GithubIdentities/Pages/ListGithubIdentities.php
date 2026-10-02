<?php

namespace App\Filament\Resources\GithubIdentities\Pages;

use App\Filament\Resources\GithubIdentities\GithubIdentityResource;
use Filament\Resources\Pages\ListRecords;

class ListGithubIdentities extends ListRecords
{
    protected static string $resource = GithubIdentityResource::class;

    protected ?string $subheading = '同步時自動建立；在「開發者」欄直接選人即可對應，同一個人可以有多個身分（不同 email／帳號）。';
}
