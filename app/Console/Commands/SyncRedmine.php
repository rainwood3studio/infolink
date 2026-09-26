<?php

namespace App\Console\Commands;

use App\Domain\Delivery\RedmineSync;
use App\Enums\SyncStatus;
use App\Models\SyncRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:sync-redmine {--full : Fetch everything and soft-delete issues Redmine no longer has} {--issues-only : Only sync issues} {--time-only : Only sync time entries}')]
#[Description('Mirror Redmine issues and time entries (incremental by default)')]
class SyncRedmine extends Command
{
    public function handle(RedmineSync $sync): int
    {
        $full = (bool) $this->option('full');
        $runs = [];

        if (! $this->option('time-only')) {
            $runs[] = $sync->syncIssues($full);
        }

        if (! $this->option('issues-only')) {
            $runs[] = $sync->syncTimeEntries($full);
        }

        foreach ($runs as $run) {
            $this->report($run);
        }

        return collect($runs)->every(fn (SyncRun $run): bool => $run->status === SyncStatus::Ok)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function report(SyncRun $run): void
    {
        $stats = collect($run->stats ?? [])
            ->map(fn ($value, string $key): string => "{$key} {$value}")
            ->implode(', ');

        $line = "{$run->job->getLabel()}: {$run->status->getLabel()} — {$stats}";

        if ($run->status === SyncStatus::Ok) {
            $this->components->info($line);

            return;
        }

        $this->components->error("{$line}\n{$run->error}");
    }
}
