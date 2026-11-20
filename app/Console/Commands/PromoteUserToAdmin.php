<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUserToAdmin extends Command
{
    protected $signature = 'users:promote {email : The email address of the user to promote}';

    protected $description = 'Grant administrator access to a user.';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user was found with that email address.');

            return self::FAILURE;
        }

        $user->update(['is_admin' => true]);

        $this->info("{$user->email} is now an administrator.");

        return self::SUCCESS;
    }
}
