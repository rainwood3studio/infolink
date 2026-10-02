<?php

namespace App\Console\Commands;

use App\Domain\Engineering\GithubSync;
use App\Enums\SyncStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:sync-github {--full : Re-walk every branch and re-read all pull requests in the retention window}')]
#[Description('Mirror GitHub repos, commits, pull requests and reviews (incremental by default, read-only)')]
class SyncGithub extends Command
{
    public function handle(GithubSync $sync): int
    {
        $run = $sync->sync((bool) $this->option('full'));
        $stats = collect($run->stats ?? [])
            ->map(fn ($value, string $key): string => "{$key} {$value}")
            ->implode(', ');

        $line = "{$run->job->getLabel()}: {$run->status->getLabel()} — {$stats}";

        if ($run->status === SyncStatus::Ok) {
            $this->components->info($line);

            return self::SUCCESS;
        }

        $this->components->error("{$line}\n{$run->error}");

        return self::FAILURE;
    }
}
