<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_seeder_creates_the_default_administrator(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@promptedit.test')->sole();

        $this->assertTrue($admin->is_admin);
        $this->assertSame('PromptEdit Admin', $admin->name);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame('0.000000000', $admin->credit_balance);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@promptedit.test')->count());
    }

    public function test_the_seeder_uses_configured_credentials(): void
    {
        config([
            'app.admin.name' => 'Assessment Admin',
            'app.admin.email' => 'admin@example.com',
            'app.admin.password' => 'super-secret',
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@example.com')->sole();

        $this->assertSame('Assessment Admin', $admin->name);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('super-secret', $admin->password));
    }

    public function test_the_seeder_promotes_an_existing_user_without_resetting_the_password(): void
    {
        $existing = User::factory()->create([
            'email' => 'admin@promptedit.test',
            'is_admin' => false,
        ]);

        $originalPassword = $existing->password;

        $this->seed(AdminUserSeeder::class);

        $existing->refresh();
        $this->assertTrue($existing->is_admin);
        $this->assertSame($originalPassword, $existing->password);
        $this->assertSame(1, User::where('email', 'admin@promptedit.test')->count());
    }
}
