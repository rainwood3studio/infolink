<?php

namespace App\Console\Commands;

use App\Domain\Notify\Notifier;
use App\Domain\Work\TodoDigest;
use App\Enums\NotificationStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:notify-todos {--dry-run : Print the message instead of sending it}')]
#[Description('Push the morning to-do digest to LINE: the three most pressing action items, plus what is delegated and overdue')]
class NotifyTodos extends Command
{
    public function handle(TodoDigest $digest, Notifier $notifier): int
    {
        $text = $digest->message();

        if ($text === null) {
            $this->components->info('沒有到期的待辦，不發送。');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line($text);

            return self::SUCCESS;
        }

        $log = $notifier->sendLineOrDefer($text);

        if (in_array($log->status, [NotificationStatus::Failed, NotificationStatus::Skipped], true)) {
            $this->components->error("LINE：{$log->status->getLabel()} — {$log->error}");

            return self::FAILURE;
        }

        $this->components->info("LINE：{$log->status->getLabel()}。");

        return self::SUCCESS;
    }
}
