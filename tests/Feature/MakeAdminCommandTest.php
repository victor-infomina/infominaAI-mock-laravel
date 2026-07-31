<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_admin_user(): void
    {
        $this->artisan('ssm:make-admin', ['email' => 'admin@example.com', 'password' => 'secret123'])
            ->assertExitCode(0);

        $user = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_updates_password_for_an_existing_admin_email(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('old-password')]);

        $this->artisan('ssm:make-admin', ['email' => 'admin@example.com', 'password' => 'new-password'])
            ->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
        $user = User::where('email', 'admin@example.com')->first();
        $this->assertTrue(Hash::check('new-password', $user->password));
    }
}
