<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_opens_the_dashboard_of_the_persons_company()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_guests_end_up_on_the_login_page()
    {
        $this->followingRedirects()
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_signed_in_users_end_up_on_the_dashboard()
    {
        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->component('srm/Dashboard'));
    }
}
