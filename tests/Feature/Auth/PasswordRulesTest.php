<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordRulesTest extends TestCase
{
    public function test_production_asks_for_length_and_an_unleaked_password_but_no_character_mix()
    {
        $this->app->detectEnvironment(fn () => 'production');
        // The leak check asks the Have I Been Pwned range API; an empty answer means "not found in leaks".
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        $passes = fn (string $password) => Validator::make(['password' => $password], ['password' => Password::default()])->passes();

        $this->assertTrue($passes('a long lowercase passphrase'));
        $this->assertFalse($passes('Sh0rt!pass'));
    }

    public function test_a_staging_server_has_the_same_rule_as_production()
    {
        $this->app->detectEnvironment(fn () => 'staging');
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        $passes = fn (string $password) => Validator::make(['password' => $password], ['password' => Password::default()])->passes();

        $this->assertTrue($passes('a long lowercase passphrase'));
        $this->assertFalse($passes('elevenchars'));
    }

    public function test_a_leaked_password_is_refused_in_production()
    {
        $this->app->detectEnvironment(fn () => 'production');
        $hash = strtoupper(sha1('correct horse battery staple'));
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':12345')]);

        $this->assertFalse(Validator::make(['password' => 'correct horse battery staple'], ['password' => Password::default()])->passes());
    }
}
