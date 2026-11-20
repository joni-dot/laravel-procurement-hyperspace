<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_safe_user_fields_are_sent_to_the_browser()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.user', [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'viewer',
                ])
                ->where('auth.can.manageUsers', false)
                ->where('auth.can.manageCategories', false)
                ->where('auth.can.manageCertificateTypes', false)
                ->where('auth.can.manageContractTypes', false)
                ->where('auth.can.manageRatingCriteria', false)
                ->where('auth.can.manageSettings', false)
            );
    }

    public function test_admins_are_told_they_can_manage_users()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.can.manageUsers', true)
                ->where('auth.can.manageCategories', true)
                ->where('auth.can.manageCertificateTypes', true)
                ->where('auth.can.manageContractTypes', true)
                ->where('auth.can.manageRatingCriteria', true)
                ->where('auth.can.manageSettings', true)
            );
    }

    public function test_every_page_links_to_the_source_code_for_guests_and_users()
    {
        config(['app.source_code_url' => 'https://example.com/changed-copy']);

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('sourceCodeUrl', 'https://example.com/changed-copy'));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('sourceCodeUrl', 'https://example.com/changed-copy'));
    }

    public function test_an_empty_source_code_setting_falls_back_to_this_repository()
    {
        $_ENV['SOURCE_CODE_URL'] = $_SERVER['SOURCE_CODE_URL'] = '';

        try {
            $config = require base_path('config/app.php');
        } finally {
            unset($_ENV['SOURCE_CODE_URL'], $_SERVER['SOURCE_CODE_URL']);
        }

        $this->assertSame('https://github.com/joni-dot/laravel-procurement-hyperspace', $config['source_code_url']);
    }

    public function test_guests_have_no_user()
    {
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('auth.user', null));
    }
}
