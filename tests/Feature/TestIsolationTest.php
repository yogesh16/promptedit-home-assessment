<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_against_an_in_memory_sqlite_database(): void
    {
        $connection = DB::connection();

        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_tests_use_isolated_cache_and_queue_drivers(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_writes_do_not_persist_across_tests(): void
    {
        $this->assertSame(0, Generation::count());

        Generation::factory()->for(User::factory())->create();

        $this->assertSame(1, Generation::count());
    }
}
