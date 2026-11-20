<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('app.admin.email');

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('app.admin.name'),
                'password' => (string) config('app.admin.password'),
                'email_verified_at' => now(),
                'is_admin' => true,
            ],
        );

        if (! $admin->is_admin) {
            $admin->forceFill(['is_admin' => true])->save();
        }
    }
}
