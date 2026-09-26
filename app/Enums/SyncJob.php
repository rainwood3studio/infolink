<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Background jobs whose runs are recorded in `sync_runs` (drives the data-freshness bar).
 */
enum SyncJob: string implements HasColor, HasLabel
{
    case RedmineIssues = 'redmine_issues';
    case RedmineTime = 'redmine_time';
    case RedmineSnapshot = 'redmine_snapshot';
    case VaultTasks = 'vault_tasks';
    case Rules = 'rules';

    public function getLabel(): string
    {
        return match ($this) {
            self::RedmineIssues => 'Redmine 議題',
            self::RedmineTime => 'Redmine 工時',
            self::RedmineSnapshot => 'Redmine 快照',
            self::VaultTasks => 'vault 待辦',
            self::Rules => '規則',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::RedmineIssues => 'info',
            self::RedmineTime => 'info',
            self::RedmineSnapshot => 'gray',
            self::VaultTasks => 'gray',
            self::Rules => 'gray',
        };
    }
}
