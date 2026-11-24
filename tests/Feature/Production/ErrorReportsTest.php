<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Monitoring\ErrorReportPrivacy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventType;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\Concerns\RunsQueuedJobsLikeAWorker;
use Tests\TestCase;

/**
 * Error reports leave our servers, so these tests send them to a recording stand-in for Sentry and
 * check what a report would carry.
 */
class ErrorReportsTest extends TestCase
{
    use RefreshDatabase, RunsQueuedJobsLikeAWorker;

    private const string DSN = 'https://public-key@errors.example.invalid/1';

    private const string RELEASE = 'test-release-1';

    private RecordingTransport $transport;

    /** Made up at run time: the reports quote this file's source lines, so a value written here would show up. */
    private string $email;

    /**
     * Reports are switched on before the app boots, as SENTRY_LARAVEL_DSN would do. Tracing is on
     * for every request too, so these tests also see what performance traces would carry.
     */
    public function createApplication()
    {
        $this->transport = new RecordingTransport;
        $this->email = 'jane.'.Str::lower(Str::random(10)).'@example.com';

        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->afterBootstrapping(LoadConfiguration::class, fn (Application $app) => $app['config']->set([
            'sentry.dsn' => self::DSN,
            'sentry.release' => self::RELEASE,
            'sentry.transport' => $this->transport,
            'sentry.traces_sample_rate' => 1.0,
        ]));

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_a_report_names_the_user_and_company_by_id_and_carries_nothing_personal()
    {
        [$name, $binding, $cookie, $header, $query, $body] = $this->secrets(6);
        $user = User::factory()->create(['name' => $name, 'email' => $this->email]);
        Route::post('_test/fail', function () use ($binding) {
            DB::select('select ? as remembered', [$binding]);

            throw new RuntimeException('Could not invite '.$this->email);
        });

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('hyperspace_session', $cookie)
            ->withHeader('Authorization', 'Bearer '.$header)
            ->post('_test/fail?token='.$query, ['password' => $body])
            ->assertServerError();

        $event = $this->onlyReport();
        $this->assertReportLeaves($event, [$this->email, $name, $binding, $cookie, $header, $query, $body]);

        $this->assertSame((string) $user->id, (string) $event->getUser()?->getId());
        $this->assertNull($event->getUser()->getEmail());
        $this->assertNull($event->getUser()->getIpAddress());
        // The request id is the one in the X-Request-Id header and on the request's log lines.
        $this->assertSame(['company_id' => (string) $this->company->id, 'request_id' => $response->headers->get('X-Request-Id')], $event->getTags());
        $this->assertSame(['method' => 'POST', 'url' => rtrim((string) config('app.url'), '/').'/_test/fail'], $event->getRequest());
        $this->assertSame('Could not invite [email]', $event->getExceptions()[0]->getValue());
        $this->assertSame('testing', $event->getEnvironment());
        $this->assertSame(self::RELEASE, $event->getRelease());
        $this->assertContains('select ? as remembered', array_map(fn (Breadcrumb $crumb) => $crumb->getMessage(), $event->getBreadcrumbs()));
    }

    public function test_database_errors_are_reported_without_the_values_of_the_query()
    {
        [$value] = $this->secrets(1);
        Route::get('_test/query', fn () => DB::select('select ?::integer as number', [$value]));

        $this->get('_test/query')->assertServerError();

        $event = $this->onlyReport();

        $this->assertReportLeaves($event, [$value]);
        $this->assertSame(
            ['SQLSTATE[22P02] in: select ?::integer as number', 'SQLSTATE[22P02]'],
            array_map(fn ($exception) => $exception->getValue(), $event->getExceptions()),
        );
    }

    public function test_values_an_error_message_quotes_are_cut_out_but_the_code_it_names_stays()
    {
        [$quoted, $doubleQuoted, $backticked, $bracketed] = $this->secrets(4);
        $phone = '+358 40 '.random_int(1_000_000, 9_999_999);
        Route::get('_test/fail', fn () => throw new RuntimeException(
            "Could not parse '{$quoted}' for App\\Foo::bar(): value \"{$doubleQuoted}\" in `{$backticked}` ({$bracketed}), "
            ."phone {$phone}; class \"App\\Models\\Supplier\" can't be found on line 12, argument #1 (\$id) [route.name]",
        ));

        $this->get('_test/fail')->assertServerError();

        $event = $this->onlyReport();
        $this->assertReportLeaves($event, [$quoted, $doubleQuoted, $backticked, $bracketed, $phone, substr($phone, -7)]);
        $this->assertSame(
            "Could not parse '[value]' for App\\Foo::bar(): value \"[value]\" in `[value]` ([value]), "
            ."phone +[number]; class \"App\\Models\\Supplier\" can't be found on line 12, argument #1 (\$id) [route.name]",
            $event->getExceptions()[0]->getValue(),
        );
        $this->assertSame(RuntimeException::class, $event->getExceptions()[0]->getType());
        $this->assertNotEmpty($event->getExceptions()[0]->getStacktrace()?->getFrames());
    }

    public function test_a_failed_background_job_names_its_company_and_nothing_from_its_payload()
    {
        $this->queueJobsForTheWorker();
        [$note] = $this->secrets(1);
        FailingJobWithPersonalData::dispatch($this->email, $note);

        $this->runQueuedJobsLikeAWorker();

        $event = $this->onlyReport();

        $this->assertReportLeaves($event, [$this->email, $note]);
        $this->assertSame(['company_id' => (string) $this->company->id], $event->getTags());
        $this->assertNull($event->getUser());
    }

    public function test_a_performance_trace_carries_neither_the_address_nor_the_token_in_it()
    {
        [$token] = $this->secrets(1);

        $this->get('/reset-password/'.$token.'?email='.urlencode($this->email))->assertOk();

        $trace = $this->onlyTrace();
        $this->assertReportLeaves($trace, [$token, $this->email]);
        $this->assertSame('/reset-password/{token}', $trace->getTransaction());
        $this->assertSame(['method' => 'GET', 'url' => rtrim((string) config('app.url'), '/').'/reset-password/{token}'], $trace->getRequest());
    }

    public function test_a_performance_trace_names_the_company_and_user_by_id_only()
    {
        $admin = User::factory()->admin()->create(['email' => $this->email]);
        $this->company->update(['slug' => 'acme-'.Str::lower(Str::random(8))]);

        $response = $this->actingAs($admin)->get(route('company.home', ['company' => $this->company->slug]))->assertOk();

        $trace = $this->onlyTrace();
        $this->assertReportLeaves($trace, [$this->company->slug, $this->email]);
        $this->assertSame('/{company}', $trace->getTransaction());
        $this->assertSame((string) $admin->id, (string) $trace->getUser()?->getId());
        $this->assertSame(['company_id' => (string) $this->company->id, 'request_id' => $response->headers->get('X-Request-Id')], $trace->getTags());
    }

    public function test_a_slow_report_server_holds_nothing_up_for_long()
    {
        $options = SentrySdk::getCurrentHub()->getClient()?->getOptions();

        $this->assertEquals(1, $options?->getHttpConnectTimeout());
        $this->assertEquals(2, $options->getHttpTimeout());
    }

    public function test_expected_answers_to_a_request_are_not_reported()
    {
        report(ValidationException::withMessages(['email' => 'The email is taken.']));
        report(new NotFoundHttpException);
        report(new ModelNotFoundException);
        report(new AuthenticationException);
        report(new AuthorizationException);
        report(new TokenMismatchException);
        report(new ThrottleRequestsException);

        $this->assertSame([], $this->transport->errors());
    }

    public function test_nothing_is_sent_without_a_dsn()
    {
        config(['sentry.dsn' => null]);

        report(new RuntimeException('Something broke.'));

        $this->assertSame([], $this->transport->errors());
    }

    public function test_only_breadcrumbs_that_name_code_are_kept_and_never_their_values()
    {
        $log = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log.info', 'Signed in '.$this->email);
        $query = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'db.sql.query', 'select * from users where email = ?', ['bindings' => [$this->email], 'executionTimeMs' => 1.2]);
        $command = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'artisan.command', 'Starting Artisan command: app:create-user', ['input' => $this->email]);

        $this->assertNull(ErrorReportPrivacy::beforeBreadcrumb($log));
        $this->assertSame(['executionTimeMs' => 1.2], ErrorReportPrivacy::beforeBreadcrumb($query)?->getMetadata());
        $this->assertSame([], ErrorReportPrivacy::beforeBreadcrumb($command)?->getMetadata());
    }

    private function onlyTrace(): Event
    {
        $transactions = $this->transport->transactions();
        $this->assertCount(1, $transactions);

        return $transactions[0];
    }

    private function onlyReport(): Event
    {
        $errors = $this->transport->errors();
        $this->assertCount(1, $errors);

        return $errors[0];
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertReportLeaves(Event $event, array $secrets): void
    {
        $options = SentrySdk::getCurrentHub()->getClient()?->getOptions();
        $this->assertNotNull($options);
        $payload = (new PayloadSerializer($options))->serialize($event);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    /**
     * @return list<string>
     */
    private function secrets(int $count): array
    {
        return array_map(fn (): string => 'secret'.Str::random(16), range(1, $count));
    }
}

final class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    /** @return list<Event> */
    public function errors(): array
    {
        return array_values(array_filter($this->events, fn (Event $event) => $event->getType() === EventType::event()));
    }

    /** @return list<Event> */
    public function transactions(): array
    {
        return array_values(array_filter($this->events, fn (Event $event) => $event->getType() === EventType::transaction()));
    }

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

class FailingJobWithPersonalData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $email, public string $note) {}

    public function handle(): void
    {
        throw new RuntimeException('The mail server did not answer.');
    }
}
