<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Every log line of a request says which request it came from, so support can go from a report to the logs.
 */
class RequestContextTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLog();
    }

    public function test_each_request_gets_its_own_id_in_a_response_header_whatever_the_visitor_sends()
    {
        $first = $this->withHeader('X-Request-Id', 'chosen-by-the-visitor')->get('/up')->headers->get('X-Request-Id');
        $second = $this->get('/up')->headers->get('X-Request-Id');

        $this->assertTrue(Str::isUuid($first));
        $this->assertTrue(Str::isUuid($second));
        $this->assertNotSame($first, $second);
    }

    public function test_an_error_log_line_names_the_request_route_address_method_and_user()
    {
        $user = User::factory()->create();
        Route::middleware('web')->post('_test/fail/{thing}', fn () => throw new RuntimeException('Something broke.'))->name('test.fail');

        $response = $this->actingAs($user)->post('_test/fail/a-value-in-the-address')->assertServerError();

        $errors = array_values(array_filter($this->logLines(), fn (array $line): bool => $line['level_name'] === 'ERROR'));
        $this->assertCount(1, $errors);
        $this->assertSame('Something broke.', $errors[0]['message']);
        $this->assertSame($user->id, $errors[0]['context']['userId']);
        $this->assertSame([
            'request_id' => $response->headers->get('X-Request-Id'),
            'ip' => '127.0.0.1',
            'method' => 'POST',
            'route' => 'test.fail',
        ], $errors[0]['extra']);
    }

    public function test_a_route_without_a_name_is_logged_by_its_pattern_never_by_its_values()
    {
        Route::get('_test/things/{thing}', fn () => Log::info('Looked at a thing'));

        $this->get('_test/things/a-value-in-the-address')->assertOk();

        [$line] = $this->logLines();
        $this->assertSame('/_test/things/{thing}', $line['extra']['route']);
        $this->assertSame('GET', $line['extra']['method']);

        // A request no route matches doesn't carry the previous request's route.
        $this->get('_test/no-such-page')->assertNotFound();
        $this->assertNull(Context::get('route'));
    }
}
