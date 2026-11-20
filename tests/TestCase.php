<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $this->assertInMemoryDatabase();

        return parent::setUpTraits();
    }

    private function assertInMemoryDatabase(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Tests must run against the in-memory SQLite database, but [%s] (%s) is configured.',
                $connection->getDriverName(),
                $connection->getDatabaseName(),
            ));
        }
    }
}
