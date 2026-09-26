<?php

namespace App\Filament\Resources\RedmineIssues\Pages;

use App\Filament\Resources\RedmineIssues\RedmineIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListRedmineIssues extends ListRecords
{
    protected static string $resource = RedmineIssueResource::class;

    protected ?string $subheading = '唯讀鏡像，資料由排程從 Redmine 同步；點議題編號到 Redmine 查看或修改。';
}
