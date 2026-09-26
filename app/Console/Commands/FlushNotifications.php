<?php

namespace App\Console\Commands;

use App\Domain\Notify\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:flush-notifications')]
#[Description('Send LINE messages that were deferred until the end of quiet hours')]
class FlushNotifications extends Command
{
    public function handle(Notifier $notifier): int
    {
        $count = $notifier->flushDeferred();

        $this->components->info($count === 0 ? 'No deferred notifications due.' : "Processed {$count} deferred notification(s).");

        return self::SUCCESS;
    }
}
