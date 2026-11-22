<?php

namespace App\Monitoring;

use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use PDOException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Throwable;

/**
 * Error reports leave our servers (Sentry or GlitchTip), so they carry only what helps find a bug:
 * the error, where in the code it happened, the route, the user's id and the open company's id.
 * This is the last step before a report is sent, and keeps only that, whatever the SDK collected.
 *
 * Wired up in config/sentry.php as plain callables, so `config:cache` can store them.
 */
final class ErrorReportPrivacy
{
    /** Breadcrumbs that only name code: routes, jobs, commands and SQL without its values. */
    private const array KEPT_BREADCRUMBS = ['route', 'db.sql.query', 'queue.job', 'artisan.command'];

    /**
     * Trace data that only describes code and timing. Everything else (the request's path and
     * query, headers, cookies, cache keys, job contents) is removed.
     */
    private const array KEPT_SPAN_DATA = [
        'code.filepath', 'code.function', 'code.lineno',
        'db.name', 'db.sql.query', 'db.system', 'db.transaction', 'server.address', 'server.port',
        'http.request.method', 'http.response.status_code',
        'messaging.destination.connection', 'messaging.destination.name', 'messaging.message.body.size',
        'messaging.message.envelope.size', 'messaging.message.id', 'messaging.message.receive.latency',
        'messaging.message.retry.count', 'messaging.system',
    ];

    /** Breadcrumb details that can hold what people typed: query values and command arguments. */
    private const array DROPPED_BREADCRUMB_DATA = ['bindings', 'input'];

    /** Anything that looks like an email address, as error messages sometimes quote one. */
    private const string EMAIL_ADDRESS = '/[^\s@<>()\[\]{}"\'`,;:]++@[^\s@<>()\[\]{}"\'`,;:.]++(?:\.[^\s@<>()\[\]{}"\'`,;:.]++)++/u';

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        self::keepOnlyIds($event);
        self::cleanExceptionMessages($event, $hint?->exception);

        if ($event->getMessage() !== null) {
            $event->setMessage(self::withoutEmailAddresses($event->getMessage()));
        }

        return $event;
    }

    /**
     * Performance traces (only sent when SENTRY_TRACES_SAMPLE_RATE is set) skip beforeSend. They
     * would name the request by its path (/reset-password/{the token}, /{company slug}/...) and
     * carry it in their data, so they get the route's pattern instead and only code-level data.
     */
    public static function beforeSendTransaction(Event $transaction, ?EventHint $hint = null): Event
    {
        self::keepOnlyIds($transaction);

        foreach ($transaction->getSpans() as $span) {
            $span->setData(array_fill_keys(array_diff(array_keys($span->getData()), self::KEPT_SPAN_DATA), null));
        }

        return $transaction;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if (! in_array($breadcrumb->getCategory(), self::KEPT_BREADCRUMBS, true)) {
            return null;
        }

        foreach (self::DROPPED_BREADCRUMB_DATA as $name) {
            $breadcrumb = $breadcrumb->withoutMetadata($name);
        }

        $message = $breadcrumb->getMessage();

        return $message === null ? $breadcrumb : $breadcrumb->withMessage(self::withoutEmailAddresses($message));
    }

    /**
     * Ids only: the user's and the open company's. The request and its name become the route's
     * pattern, and trace data keeps only what describes code.
     */
    private static function keepOnlyIds(Event $event): void
    {
        $event->setUser(self::user());
        $event->setTags(self::tags());
        $event->setRequest(self::request($event->getRequest()));
        // Laravel's shared context and an exception's own context() can hold anything.
        $event->setExtra([]);
        $event->setContext('laravel', []);

        // Named after a path (/reset-password/{the token}) until a route matches, and after the
        // path itself when none does. Route names, jobs and commands don't start with a slash.
        if (str_starts_with((string) $event->getTransaction(), '/')) {
            $event->setTransaction(self::routePattern() ?? 'unmatched route');
        }

        $trace = $event->getContexts()['trace'] ?? null;

        if (is_array($trace) && isset($trace['data']) && is_array($trace['data'])) {
            $trace['data'] = array_intersect_key($trace['data'], array_flip(self::KEPT_SPAN_DATA));
            $event->setContext('trace', $trace);
        }
    }

    /** Only the id, and only if the request has already loaded the user: no query while reporting. */
    private static function user(): ?UserDataBag
    {
        $auth = auth()->guard();
        $id = $auth->hasUser() ? $auth->id() : null;

        return is_int($id) || is_string($id) ? UserDataBag::createFromUserIdentifier($id) : null;
    }

    /**
     * @return array<string, string>
     */
    private static function tags(): array
    {
        $companyId = app(ReportedCompany::class)->id();

        return $companyId === null ? [] : ['company_id' => (string) $companyId];
    }

    /**
     * The method and the route's pattern (/{company}/suppliers/{supplier}): no company address,
     * query string, headers, cookies or body.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function request(array $request): array
    {
        if ($request === []) {
            return [];
        }

        $route = self::routePattern();

        return array_filter([
            'method' => $request['method'] ?? null,
            'url' => $route === null ? null : rtrim((string) config('app.url'), '/').$route,
        ]);
    }

    /** Such as /{company}/suppliers/{supplier}: never the values in the address. */
    private static function routePattern(): ?string
    {
        $route = request()->route();

        return $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null;
    }

    /**
     * Database errors quote the values of the failed query ("Key (email)=(...) already exists"),
     * so they are reported by their SQLSTATE code and SQL with placeholders. The full message
     * stays in the server's own log.
     */
    private static function cleanExceptionMessages(Event $event, ?Throwable $exception): void
    {
        $chain = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $chain[] = $current;
        }

        // The SDK lists the exception and then its previous ones, in the same order as $chain.
        foreach ($event->getExceptions() as $index => $reported) {
            $throwable = $chain[$index] ?? null;

            $reported->setValue($throwable instanceof PDOException
                ? self::databaseErrorMessage($throwable)
                : self::withoutEmailAddresses($reported->getValue()));
        }
    }

    private static function databaseErrorMessage(PDOException $exception): string
    {
        $code = 'SQLSTATE['.$exception->getCode().']';

        return $exception instanceof QueryException ? $code.' in: '.$exception->getSql() : $code;
    }

    private static function withoutEmailAddresses(string $text): string
    {
        return preg_replace(self::EMAIL_ADDRESS, '[email]', $text) ?? '[removed]';
    }
}
