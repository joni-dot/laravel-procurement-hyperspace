<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_has_security_headers()
    {
        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy');
    }

    public function test_hsts_is_only_sent_over_https()
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get(str_replace('http://', 'https://', route('login')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_signed_in_pages_are_kept_out_of_the_browser_cache()
    {
        $this->get(route('login'))->assertHeader('Cache-Control', 'no-cache, private');

        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
