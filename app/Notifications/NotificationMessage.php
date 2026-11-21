<?php

namespace App\Notifications;

use Illuminate\Support\Str;
use JsonSerializable;

/**
 * A notification's sentence, translated on the server, so the notifications page and the summary email
 * share one wording. Dates stay dates ({"date": "2026-11-03"}) so the page shows them in the reader's own
 * format (formatDate() in the browser); the email writes them as "2026-11-03", like the other emails.
 *
 *     NotificationMessage::make(__(':package was due on :date.'), ['package' => 'P1 Pump', 'date' => NotificationMessage::date('2026-11-03')])
 *
 * The line goes through __() at the call, so the texts are found for lang/en.json like any other.
 *
 * becomes the parts ["P1 Pump was due on ", {"date": "2026-11-03"}, "."].
 */
final readonly class NotificationMessage implements JsonSerializable
{
    // Characters from Unicode's private use area mark where a date or a nested message goes. Plain
    // values never contain them (they are removed), so typed text can't pretend to be a marker.
    private const string OPEN = "\u{E000}";

    private const string CLOSE = "\u{E001}";

    /**
     * @param  list<string|array{date: string}>  $parts
     */
    private function __construct(public array $parts) {}

    public static function date(string $isoDate): self
    {
        return new self([['date' => $isoDate]]);
    }

    public static function text(string $text): self
    {
        return new self([self::withoutMarkers($text)]);
    }

    /**
     * Fills a translated line's placeholders the way __() does (":name", ":Name", ":NAME"). A replacement
     * may also be a date or another message, such as a list of reasons.
     *
     * @param  array<string, string|int|self>  $replacements
     */
    public static function make(string $translatedLine, array $replacements = []): self
    {
        $embedded = [];
        $plain = [];

        foreach ($replacements as $name => $value) {
            if ($value instanceof self) {
                $plain[$name] = self::OPEN.count($embedded).self::CLOSE;
                $embedded[] = $value;
            } else {
                $plain[$name] = self::withoutMarkers((string) $value);
            }
        }

        return self::expand(self::fill($translatedLine, $plain), $embedded);
    }

    /**
     * @param  array<self>  $messages
     */
    public static function join(string $separator, array $messages): self
    {
        $parts = [];

        foreach (array_values($messages) as $index => $message) {
            if ($index > 0) {
                $parts[] = $separator;
            }

            array_push($parts, ...$message->parts);
        }

        return new self(self::mergeTexts($parts));
    }

    /**
     * The sentence for an email or a log: dates as "YYYY-MM-DD".
     */
    public function plain(): string
    {
        return implode('', array_map(fn (string|array $part) => is_string($part) ? $part : $part['date'], $this->parts));
    }

    /**
     * @return list<string|array{date: string}>
     */
    public function jsonSerialize(): array
    {
        return $this->parts;
    }

    /**
     * @param  list<self>  $embedded
     */
    private static function expand(string $line, array $embedded): self
    {
        $pieces = preg_split('/'.self::OPEN.'(\d+)'.self::CLOSE.'/u', $line, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        $parts = [];

        foreach ($pieces as $index => $piece) {
            // Every odd piece is the number of a marker; the others are text.
            if ($index % 2 === 0) {
                $parts[] = $piece;
            } else {
                array_push($parts, ...$embedded[(int) $piece]->parts);
            }
        }

        return new self(self::mergeTexts($parts));
    }

    /**
     * @param  list<string|array{date: string}>  $parts
     * @return list<string|array{date: string}>
     */
    private static function mergeTexts(array $parts): array
    {
        $merged = [];

        foreach ($parts as $part) {
            $last = array_key_last($merged);

            if (is_string($part) && $last !== null && is_string($merged[$last])) {
                $merged[$last] .= $part;
            } elseif ($part !== '') {
                $merged[] = $part;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private static function fill(string $line, array $replacements): string
    {
        $placeholders = [];

        foreach ($replacements as $name => $value) {
            $placeholders[':'.Str::ucfirst($name)] = Str::ucfirst($value);
            $placeholders[':'.Str::upper($name)] = Str::upper($value);
            $placeholders[':'.$name] = $value;
        }

        // One pass, longest placeholder first, so a value that itself holds ":name" is never replaced again.
        return strtr($line, $placeholders);
    }

    private static function withoutMarkers(string $text): string
    {
        return str_replace([self::OPEN, self::CLOSE], '', $text);
    }
}
