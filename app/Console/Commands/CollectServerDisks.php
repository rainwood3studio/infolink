<?php

namespace App\Console\Commands;

use App\Domain\Infra\ServerDiskCollector;
use App\Enums\SyncStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:collect-server-disks')]
#[Description('Collect disk usage from every SSM managed server (read-only df via Run Command)')]
class CollectServerDisks extends Command
{
    public function handle(ServerDiskCollector $collector): int
    {
        $run = $collector->collect();
        $stats = collect($run->stats ?? [])
            ->map(fn ($value, string $key): string => "{$key} {$value}")
            ->implode(', ');

        if ($run->status === SyncStatus::Ok) {
            $this->components->info("伺服器硬碟: {$stats}");

            return self::SUCCESS;
        }

        $this->components->error("伺服器硬碟: {$stats}\n{$run->error}");

        return self::FAILURE;
    }
}
