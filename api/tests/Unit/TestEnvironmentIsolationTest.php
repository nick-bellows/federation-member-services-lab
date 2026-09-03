<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Regression test for INCIDENT-000: the api container caches configuration
 * and routes onto the bind mount shared with the tooling container. A test
 * process that read those caches would ignore .env.testing and point its
 * migrate:fresh at the development database. phpunit.xml relocates every
 * cache path for tests; this test fails if that isolation is ever removed.
 */
class TestEnvironmentIsolationTest extends TestCase
{
    public function test_the_test_process_never_reads_the_containers_config_cache(): void
    {
        $this->assertStringEndsWith('config-testing.php', $this->app->getCachedConfigPath());
        $this->assertStringEndsWith('routes-testing.php', $this->app->getCachedRoutesPath());
        $this->assertStringEndsWith('events-testing.php', $this->app->getCachedEventsPath());

        $this->assertFalse($this->app->configurationIsCached());
        $this->assertFalse($this->app->routesAreCached());
        $this->assertFalse($this->app->eventsAreCached());
    }

    /**
     * The suite runs on SQLite in memory locally and on MariaDB in CI; the
     * invariant is that the database in use is the one the testing environment
     * names (.env.testing, phpunit.xml or the CI job), never a cached
     * development configuration.
     */
    public function test_the_test_database_is_the_one_the_testing_environment_names(): void
    {
        $this->assertSame('testing', $this->app->environment());

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $this->assertSame(env('DB_CONNECTION', 'sqlite'), $connection);
        $this->assertSame(env('DB_DATABASE', ':memory:'), $database);
        $this->assertNotSame('verein', $database);

        if ($connection === 'sqlite') {
            $this->assertSame(':memory:', $database);
        }
    }
}
