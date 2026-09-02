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

    public function test_the_test_database_is_sqlite_in_memory(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
