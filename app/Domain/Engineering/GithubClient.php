<?php

namespace App\Domain\Engineering;

use App\Domain\Engineering\Exceptions\GithubRequestException;
use App\Domain\Engineering\Exceptions\GithubTimeoutException;
use App\Domain\Engineering\Exceptions\GithubUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin read-only client for the GitHub GraphQL and REST APIs (config services.github.*). The app never writes
 * to GitHub. Rate limiting (403/429 with an exhausted or secondary limit, GraphQL RATE_LIMITED) counts as
 * unavailable, so the run fails quietly and the next scheduled run resumes.
 */
class GithubClient
{
    public const string BASE_URL = 'https://api.github.com';

    /**
     * Backoff between attempts. Heavy GraphQL pages (commit history with line counts) intermittently time out
     * on GitHub's side as 502/504, so gateway errors are retried as well as connection failures.
     *
     * @var list<int>
     */
    public const array RETRY_DELAYS_MS = [1000, 3000];

    /**
     * Run a GraphQL query and return its `data`.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws GithubUnavailableException when GitHub cannot be reached, answers 5xx or is rate limited
     * @throws GithubRequestException when GitHub refuses the request, the query errors or it is not configured
     */
    public function graphql(string $query, array $variables = []): array
    {
        $json = $this->send('graphql', fn (PendingRequest $request): Response => $request->post('graphql', [
            'query' => $query,
            'variables' => (object) $variables,
        ]));

        $errors = $json['errors'] ?? [];

        if ($errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('; ') ?: 'unknown error';

            if (collect($errors)->contains(fn ($error): bool => ($error['type'] ?? null) === 'RATE_LIMITED')) {
                throw new GithubUnavailableException("GitHub GraphQL rate limited: {$message}");
            }

            throw new GithubRequestException("GitHub GraphQL error: {$message}");
        }

        if (! is_array($json['data'] ?? null)) {
            throw new GithubUnavailableException('GitHub GraphQL returned no data.');
        }

        return $json['data'];
    }

    /**
     * GET a REST endpoint, e.g. `get('repos/infolinktw/app/commits/abc123')`.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws GithubUnavailableException when GitHub cannot be reached, answers 5xx or is rate limited
     * @throws GithubRequestException when GitHub refuses the request (4xx) or is not configured
     */
    public function get(string $path, array $query = []): array
    {
        $path = ltrim($path, '/');

        return $this->send($path, fn (PendingRequest $request): Response => $request->get(
            $path,
            array_filter($query, fn ($value) => $value !== null),
        ));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(string $path, callable $call): array
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException $exception) {
            throw new GithubTimeoutException("GitHub unreachable: {$exception->getMessage()}", previous: $exception);
        }

        if (in_array($response->status(), [502, 504], true)) {
            throw new GithubTimeoutException("GitHub returned HTTP {$response->status()} for {$path}.");
        }

        if ($response->serverError()) {
            throw new GithubUnavailableException("GitHub returned HTTP {$response->status()} for {$path}.");
        }

        if ($this->isRateLimited($response)) {
            throw new GithubUnavailableException("GitHub rate limit hit for {$path} (HTTP {$response->status()}).");
        }

        if ($response->failed()) {
            $message = $response->json('message');
            $detail = is_string($message) && $message !== '' ? " ({$message})" : '';

            throw new GithubRequestException("GitHub refused {$path}: HTTP {$response->status()}{$detail}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new GithubUnavailableException("GitHub returned a non-JSON body for {$path}.");
        }

        return $json;
    }

    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if ($response->status() !== 403) {
            return false;
        }

        return $response->header('x-ratelimit-remaining') === '0'
            || $response->header('retry-after') !== ''
            || str_contains(mb_strtolower((string) $response->json('message')), 'rate limit');
    }

    protected function request(): PendingRequest
    {
        $token = (string) config('services.github.token');

        if ($token === '') {
            throw new GithubRequestException('GitHub is not configured (GITHUB_TOKEN).');
        }

        return Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->acceptJson()
            ->timeout((int) config('services.github.timeout', 30))
            ->retry(self::RETRY_DELAYS_MS, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [502, 503, 504], true)), throw: false);
    }
}
