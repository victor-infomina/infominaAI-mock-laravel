<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeAdmin extends Command
{
    protected $signature = 'ssm:make-admin {email} {password}';

    protected $description = 'Create or update the single admin user allowed to log into the SSM mock admin UI';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($password)],
        );

        $this->info("Admin user ready: {$email}");

        return self::SUCCESS;
    }
}
