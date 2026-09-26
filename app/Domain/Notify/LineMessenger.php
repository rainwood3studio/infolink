<?php

namespace App\Domain\Notify;

use App\Domain\Notify\Exceptions\LineNotConfiguredException;
use App\Domain\Notify\Exceptions\LineRequestException;
use App\Domain\Notify\Exceptions\LineUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Minimal LINE Messaging API client: pushes a text message to the single configured user
 * (config services.line.channel_access_token / user_id).
 */
class LineMessenger
{
    public const string PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    /**
     * LINE rejects text messages longer than this.
     */
    public const int MAX_TEXT_LENGTH = 5000;

    public function isConfigured(): bool
    {
        return filled(config('services.line.channel_access_token')) && filled(config('services.line.user_id'));
    }

    /**
     * @throws LineNotConfiguredException when the token or user id is missing
     * @throws LineRequestException when LINE refuses the request (4xx)
     * @throws LineUnavailableException when LINE cannot be reached or answers 5xx
     */
    public function push(string $text): void
    {
        if (! $this->isConfigured()) {
            throw new LineNotConfiguredException('LINE not configured');
        }

        try {
            $response = Http::withToken((string) config('services.line.channel_access_token'))
                ->acceptJson()
                ->timeout(10)
                ->post(self::PUSH_URL, [
                    'to' => config('services.line.user_id'),
                    'messages' => [
                        ['type' => 'text', 'text' => Str::limit($text, self::MAX_TEXT_LENGTH - 1, '…')],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new LineUnavailableException("LINE unreachable: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->serverError()) {
            throw new LineUnavailableException("LINE returned HTTP {$response->status()}: ".Str::limit($response->body(), 300));
        }

        if ($response->failed()) {
            throw new LineRequestException("LINE refused the push: HTTP {$response->status()}: ".Str::limit($response->body(), 300));
        }
    }
}
