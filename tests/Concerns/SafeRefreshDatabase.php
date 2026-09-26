<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * RefreshDatabase that refuses to wipe anything but an in-memory SQLite or a `*_test` database, so a mis-set
 * environment (e.g. container env vars overriding phpunit.xml) can never destroy real data.
 */
trait SafeRefreshDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to refresh the [{$connection}] database [{$database}] during tests.");
        }
    }
}
