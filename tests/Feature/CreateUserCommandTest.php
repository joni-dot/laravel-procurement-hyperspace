<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_account_with_a_hashed_password_and_no_company()
    {
        $this->askForAccount('Anna@Example.com')->assertSuccessful();

        $user = User::sole();

        $this->assertSame('anna@example.com', $user->email);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertFalse($user->is_platform_admin);
        $this->assertSame(0, $user->memberships()->count());
    }

    public function test_it_can_create_a_platform_admin()
    {
        $this->askForAccount('anna@example.com', ['--platform-admin' => true])
            ->expectsOutputToContain('two-factor authentication')
            ->assertSuccessful();

        $this->assertTrue(User::sole()->is_platform_admin);
    }

    public function test_it_rejects_a_taken_email()
    {
        User::factory()->create(['email' => 'anna@example.com']);

        $this->askForAccount('anna@example.com', ['--platform-admin' => true])->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertFalse(User::sole()->is_platform_admin);
    }

    public function test_it_rejects_mismatched_passwords()
    {
        $this->artisan('app:create-user')
            ->expectsQuestion('Name', 'Anna Buyer')
            ->expectsQuestion('Email', 'anna@example.com')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'something-else')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function askForAccount(string $email, array $options = []): mixed
    {
        return $this->artisan('app:create-user', $options)
            ->expectsQuestion('Name', 'Anna Buyer')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'correct-horse-battery');
    }
}
