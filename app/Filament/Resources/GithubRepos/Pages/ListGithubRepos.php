<?php

namespace App\Filament\Resources\GithubRepos\Pages;

use App\Filament\Resources\GithubRepos\GithubRepoResource;
use Filament\Resources\Pages\ListRecords;

class ListGithubRepos extends ListRecords
{
    protected static string $resource = GithubRepoResource::class;
}
