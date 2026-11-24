<?php

namespace Tests\Concerns;

use App\Monitoring\SecurityEvents;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Sends the default log channel to memory, and reads its lines back as the production channel (json)
 * writes them, with the request context in "extra".
 */
trait ReadsSecurityLog
{
    private TestHandler $logLines;

    protected function captureLog(): void
    {
        $this->logLines = $lines = new TestHandler(Level::Debug);

        config([
            'logging.default' => 'captured',
            'logging.channels.captured' => ['driver' => 'custom', 'via' => fn (): Logger => new Logger('testing', [$lines])],
        ]);
        // Starts afresh when called again in a test.
        Log::forgetChannel('captured');
    }

    /**
     * Every line, decoded from its JSON.
     *
     * @return list<array<string, mixed>>
     */
    protected function logLines(): array
    {
        $formatter = new JsonFormatter;

        return array_map(
            fn (LogRecord $record): array => json_decode($formatter->format($record), true, flags: JSON_THROW_ON_ERROR),
            $this->logLines->getRecords(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function securityLines(): array
    {
        return array_values(array_filter($this->logLines(), fn (array $line): bool => $line['message'] === SecurityEvents::MESSAGE));
    }

    /**
     * @return list<string>
     */
    protected function securityEvents(): array
    {
        return array_map(fn (array $line): string => $line['context']['event'], $this->securityLines());
    }

    /**
     * The one line logged under this name; fails when there is none or more than one.
     *
     * @return array<string, mixed>
     */
    protected function securityLine(string $event): array
    {
        $lines = array_values(array_filter($this->securityLines(), fn (array $line): bool => $line['context']['event'] === $event));
        $this->assertCount(1, $lines, "Expected one [{$event}] line; logged: ".implode(', ', $this->securityEvents()));

        return $lines[0];
    }

    /**
     * Names by id only: no email address, password, code, token or secret anywhere in the security log.
     *
     * @param  list<string>  $secrets
     */
    protected function assertSecurityLogLeaves(array $secrets): void
    {
        $log = json_encode($this->securityLines(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertNotSame('[]', $log, 'Nothing was logged.');
        $this->assertDoesNotMatchRegularExpression('/[^\s"@]+@[^\s"@]+\.[a-z]+/i', $log, 'An email address was logged.');

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }
}
