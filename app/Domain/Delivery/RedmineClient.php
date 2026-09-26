<?php

namespace App\Domain\Delivery;

use App\Domain\Delivery\Exceptions\RedmineRequestException;
use App\Domain\Delivery\Exceptions\RedmineUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Thin read-only client for the Redmine REST API (config services.redmine.*). The app never writes to Redmine.
 */
class RedmineClient
{
    public const int PAGE_SIZE = 100;

    /**
     * GET a Redmine JSON endpoint, e.g. `get('issues.json', ['status_id' => '*'])`.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws RedmineUnavailableException when Redmine cannot be reached or answers 5xx
     * @throws RedmineRequestException when Redmine refuses the request (4xx) or is not configured
     */
    public function get(string $path, array $query = []): array
    {
        $path = ltrim($path, '/');

        try {
            $response = $this->request()->get($path, array_filter($query, fn ($value) => $value !== null));
        } catch (ConnectionException $exception) {
            throw new RedmineUnavailableException("Redmine unreachable: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->serverError()) {
            throw new RedmineUnavailableException("Redmine returned HTTP {$response->status()} for {$path}.");
        }

        if ($response->failed()) {
            throw new RedmineRequestException("Redmine refused {$path}: HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RedmineUnavailableException("Redmine returned a non-JSON body for {$path}.");
        }

        return $json;
    }

    /**
     * Lazily walk every page of a Redmine list endpoint (offset/limit), yielding the records one by one.
     * The collection key is derived from the path (`issues.json` → `issues`, `time_entries.json` → `time_entries`).
     *
     * @param  array<string, scalar|null>  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): LazyCollection
    {
        $key = basename(ltrim($path, '/'), '.json');

        return LazyCollection::make(function () use ($path, $query, $key) {
            $offset = 0;

            do {
                $page = $this->get($path, [...$query, 'offset' => $offset, 'limit' => self::PAGE_SIZE]);
                $records = $page[$key] ?? [];

                foreach ($records as $record) {
                    yield $record;
                }

                $offset += max(1, (int) ($page['limit'] ?? self::PAGE_SIZE));
                $total = (int) ($page['total_count'] ?? 0);
            } while ($records !== [] && $offset < $total);
        });
    }

    protected function request(): PendingRequest
    {
        $url = (string) config('services.redmine.url');
        $key = (string) config('services.redmine.key');

        if ($url === '' || $key === '') {
            throw new RedmineRequestException('Redmine is not configured (REDMINE_URL / REDMINE_API_KEY).');
        }

        return Http::baseUrl(rtrim($url, '/'))
            ->withHeaders(['X-Redmine-API-Key' => $key])
            ->acceptJson()
            ->timeout((int) config('services.redmine.timeout', 15))
            ->retry(2, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);
    }
}
