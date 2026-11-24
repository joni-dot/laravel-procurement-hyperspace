<?php

namespace Tests\Feature\Production;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ProductionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_goes_out_only_over_an_encrypted_connection_outside_development()
    {
        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
    }

    public function test_the_production_example_keeps_email_encryption_required()
    {
        $this->assertSame('true', $this->productionExample()['MAIL_REQUIRE_TLS']);
    }

    public function test_the_production_session_cookie_is_bound_to_this_host_over_https()
    {
        $example = $this->productionExample();
        $this->assertStringStartsWith('__Host-', $example['SESSION_COOKIE']);
        $this->assertSame('/', $example['SESSION_PATH']);
        $this->assertSame('true', $example['SESSION_SECURE_COOKIE']);
        $this->assertContains($example['SESSION_DOMAIN'], ['', 'null']);

        config([
            'session.cookie' => $example['SESSION_COOKIE'],
            'session.path' => $example['SESSION_PATH'],
            'session.secure' => true,
            'session.domain' => null,
        ]);
        // The session was opened while the app started, under the development cookie name.
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $cookie = $this->sessionCookieFrom(str_replace('http://', 'https://', route('login')), $example['SESSION_COOKIE']);

        // Browsers accept a __Host- cookie only when it is Secure, for path / and names no domain.
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
    }

    public function test_stored_files_have_no_address_of_their_own()
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->get('/storage/companies/1/documents/1/file.pdf')->assertNotFound();
    }

    /**
     * @return array<string, string|null>
     */
    private function productionExample(): array
    {
        return Dotenv::parse((string) file_get_contents(base_path('.env.production.example')));
    }

    public function test_secure_session_cookies_get_the_host_prefix_without_being_told()
    {
        $this->assertStringStartsWith('__Host-', $this->sessionConfigWith(['SESSION_SECURE_COOKIE' => 'true'])['cookie']);
        // Browsers refuse a __Host- cookie that names a domain, and plain http (development) can't use one.
        $this->assertStringStartsNotWith('__Host-', $this->sessionConfigWith(['SESSION_SECURE_COOKIE' => 'true', 'SESSION_DOMAIN' => '.example.com'])['cookie']);
        $this->assertStringStartsNotWith('__Host-', $this->sessionConfigWith(['SESSION_SECURE_COOKIE' => 'false'])['cookie']);
    }

    /**
     * config/session.php as it reads with these settings and no SESSION_COOKIE.
     *
     * @param  array<string, string>  $settings
     * @return array<string, mixed>
     */
    private function sessionConfigWith(array $settings): array
    {
        $names = ['SESSION_COOKIE', 'SESSION_SECURE_COOKIE', 'SESSION_DOMAIN'];
        $saved = array_map(fn (string $name) => [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)], array_combine($names, $names));

        try {
            foreach ($names as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
                if (isset($settings[$name])) {
                    $_SERVER[$name] = $_ENV[$name] = $settings[$name];
                    putenv("{$name}={$settings[$name]}");
                }
            }

            return require config_path('session.php');
        } finally {
            foreach ($saved as $name => [$server, $env, $put]) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($put !== false) {
                    putenv("{$name}={$put}");
                }
            }
        }
    }

    private function sessionCookieFrom(string $url, string $name): Cookie
    {
        $cookies = $this->get($url)->assertOk()->headers->getCookies();
        $cookie = collect($cookies)->first(fn (Cookie $cookie) => $cookie->getName() === $name);
        $this->assertNotNull($cookie, "No {$name} cookie was set.");

        return $cookie;
    }
}
