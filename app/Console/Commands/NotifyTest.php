<?php

namespace App\Console\Commands;

use App\Domain\Notify\Notifier;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:notify-test {--channel=line : line or database}')]
#[Description('Send a test notification right away (no quiet hours, no retry) to check the channel setup')]
class NotifyTest extends Command
{
    public function handle(Notifier $notifier): int
    {
        $channel = NotificationChannel::tryFrom((string) $this->option('channel'));

        if ($channel === null) {
            $this->components->error('Unknown channel; use --channel=line or --channel=database.');

            return self::INVALID;
        }

        $text = '✅ INFOLINK 測試通知 '.now()->format('Y-m-d H:i');

        $log = match ($channel) {
            NotificationChannel::Line => $notifier->sendLine($text),
            NotificationChannel::Database => $notifier->sendToDatabase($text, '這是一則測試通知。', rtrim((string) config('app.url'), '/').'/admin'),
        };

        if ($log->status !== NotificationStatus::Sent) {
            $this->components->error("{$channel->getLabel()}：{$log->status->getLabel()} — {$log->error}");

            return self::FAILURE;
        }

        $this->components->info("{$channel->getLabel()}：已送出。");

        return self::SUCCESS;
    }
}
