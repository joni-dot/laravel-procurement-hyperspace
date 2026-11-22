<?php

namespace Tests\Feature\Production;

use App\Http\TrustedProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/request-seen-by-app', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]);

        Route::get('/_test/addresses-built-by-app', fn () => [
            'url' => url('/somewhere'),
            'route' => route('home'),
        ]);
    }

    public function test_without_trusted_proxies_forwarded_headers_are_ignored()
    {
        $this->trustProxies('');

        $this->requestThroughProxy(from: '10.0.0.5')
            ->assertExactJson(['secure' => false, 'ip' => '10.0.0.5']);
    }

    public function test_a_listed_proxy_is_believed()
    {
        $this->trustProxies('10.0.0.4, 10.0.0.5');

        $this->requestThroughProxy(from: '10.0.0.5')
            ->assertExactJson(['secure' => true, 'ip' => '203.0.113.7']);
    }

    public function test_an_address_not_on_the_list_is_not_believed()
    {
        $this->trustProxies('10.0.0.4');

        $this->requestThroughProxy(from: '198.51.100.9')
            ->assertExactJson(['secure' => false, 'ip' => '198.51.100.9']);
    }

    public function test_a_star_trusts_whoever_connects()
    {
        $this->trustProxies('*');

        $this->requestThroughProxy(from: '10.0.0.5')
            ->assertExactJson(['secure' => true, 'ip' => '203.0.113.7']);
    }

    public function test_a_trusted_proxy_cannot_pass_on_a_visitors_port_or_path_prefix()
    {
        $this->trustProxies('10.0.0.5');

        $honest = $this->addressesBuiltThroughProxy([]);
        $spoofed = $this->addressesBuiltThroughProxy(['X-Forwarded-Prefix' => '/evil', 'X-Forwarded-Port' => '4444']);

        $this->assertSame($honest, $spoofed);
        $this->assertStringNotContainsString('evil', (string) json_encode($spoofed));
        $this->assertStringNotContainsString('4444', (string) json_encode($spoofed));
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>
     */
    private function addressesBuiltThroughProxy(array $extraHeaders): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7', ...$extraHeaders])
            ->getJson('/_test/addresses-built-by-app')
            ->assertOk()
            ->json();
    }

    private function trustProxies(string $setting): void
    {
        config(['app.trusted_proxies' => $setting]);
        TrustedProxies::applyConfiguration();
    }

    private function requestThroughProxy(string $from): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('/_test/request-seen-by-app');
    }
}
