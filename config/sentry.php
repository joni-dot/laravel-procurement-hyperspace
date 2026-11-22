<?php

use App\Monitoring\ErrorReportPrivacy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
|--------------------------------------------------------------------------
| Error reports (Sentry or GlitchTip)
|--------------------------------------------------------------------------
|
| Off unless SENTRY_LARAVEL_DSN is set: self-hosters opt in. Reports leave our servers, so they
| carry only the error, where it happened, the route, the user's id and the open company's id.
| Nothing here may be switched to collect more from the environment; ErrorReportPrivacy removes
| anything the SDK still collects. See docs/monitoring.md.
|
| @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
*/

// An empty line in .env ("SENTRY_RELEASE=") counts as not set.
$setting = fn (string $name): ?string => filled(env($name)) ? (string) env($name) : null;
$sampleRate = fn (string $name): ?float => filled(env($name)) ? (float) env($name) : null;

return [

    'dsn' => $setting('SENTRY_LARAVEL_DSN'),

    // The image's version (APP_VERSION) tells which release an error belongs to.
    'release' => $setting('SENTRY_RELEASE') ?? $setting('APP_VERSION'),

    // Empty means APP_ENV.
    'environment' => $setting('SENTRY_ENVIRONMENT'),

    'sample_rate' => $sampleRate('SENTRY_SAMPLE_RATE') ?? 1.0,

    // Performance tracing and profiling are off unless a rate is set: they would send every
    // request's timings and add work to each request.
    'traces_sample_rate' => $sampleRate('SENTRY_TRACES_SAMPLE_RATE'),
    'profiles_sample_rate' => $sampleRate('SENTRY_PROFILES_SAMPLE_RATE'),

    'enable_logs' => false,
    'enable_metrics' => false,

    // Fixed, not read from the environment: privacy is not a setting.
    'send_default_pii' => false,
    'max_request_body_size' => 'never',

    // What the SDK may collect at all. ErrorReportPrivacy::beforeSend() checks the result again.
    'data_collection' => [
        'user_info' => false,
        'cookies' => ['mode' => 'off'],
        'http_headers' => ['mode' => 'off'],
        'http_bodies' => [],
        'url_query_params' => ['mode' => 'off'],
        'gen_ai' => ['inputs' => false, 'outputs' => false],
        'database_query_data' => false,
        // Queued jobs carry records and email addresses in their payload.
        'queues' => false,
        // Function arguments in the stack trace can be passwords or personal data.
        'stack_frame_variables' => ['mode' => 'off'],
        'frame_context_lines' => 5,
    ],

    'before_send' => [ErrorReportPrivacy::class, 'beforeSend'],
    'before_send_transaction' => [ErrorReportPrivacy::class, 'beforeSendTransaction'],
    'before_breadcrumb' => [ErrorReportPrivacy::class, 'beforeBreadcrumb'],

    // Seconds. Reports are sent while the request or job waits, so a report server that is down
    // or slow must never hold up the people using the app or the background jobs.
    'http_timeout' => 2,
    'http_connect_timeout' => 1,

    // Expected answers to a person's request, not faults of the app. Laravel doesn't report them
    // either; listing them here also keeps them out when something reports them directly.
    'ignore_exceptions' => [
        AuthenticationException::class,
        AuthorizationException::class,
        HttpExceptionInterface::class,
        ModelNotFoundException::class,
        RecordsNotFoundException::class,
        SuspiciousOperationException::class,
        TokenMismatchException::class,
        ValidationException::class,
    ],

    'ignore_transactions' => ['/up', '/health'],

    'breadcrumbs' => [
        // Log messages and cache keys can hold email addresses (the sign-in rate limit's key does).
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        // The SQL only, never its values.
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'crons' => [
        'monitor_scheduled_tasks' => false,
        'exclude_scheduled_tasks' => [],
    ],

    // Used only when a traces sample rate is set.
    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => true,
        'livewire' => false,
        // Their descriptions hold the full address called, query string included.
        'http_client_requests' => false,
        'http_client_requests_origin' => false,
        'http_client_requests_origin_threshold_ms' => 250,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => true,
        'gen_ai' => false,
        'default_integrations' => true,
    ],

];
