<?php

namespace Tests\Feature;

use App\Auth\StrongSignIn;
use App\Models\User;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Addresses and slugs are tidied before validation; a list where text belongs is an ordinary
 * validation error, not a server error.
 */
class TextInputTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_forms_refuse_a_list_where_text_belongs()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.store'), ['name' => 'X', 'email' => ['a@example.com'], 'role' => 'viewer'])
            ->assertSessionHasErrors('email');

        $this->actingAs(User::factory()->create())
            ->patch(route('profile.update'), ['name' => 'X', 'email' => ['a@example.com'], 'current_password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    public function test_platform_forms_refuse_a_list_where_text_belongs()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $this->actingAs($platformAdmin)->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $platformAdmin->id]);

        $this->post(route('platform.companies.store'), ['name' => 'X', 'slug' => ['x'], 'admin_name' => 'X', 'admin_email' => ['a@example.com']])
            ->assertSessionHasErrors(['slug', 'admin_email']);
        $this->put(route('platform.companies.update', $this->company), ['name' => 'X', 'slug' => ['x']])
            ->assertSessionHasErrors('slug');
        $this->post(route('platform.companies.first-admin', $this->company), ['admin_name' => 'X', 'admin_email' => ['a@example.com']])
            ->assertSessionHasErrors('admin_email');
    }
}
