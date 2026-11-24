<?php

namespace App\Companies;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Daily limits per company on what costs the service most: invitation emails and stored files. The
 * per-minute limits on the routes stop bursts; these stop one account (or a stolen session) from
 * sending tens of thousands of emails or filling the storage over a day. Counted per company, so a
 * company's members share them.
 *
 * Only work that is actually done counts: the limit is checked inside the controller, after the
 * company, the person's rights and the form are checked, and counted once the work succeeded. So
 * nobody without the right to invite or upload in a company can use up its limits.
 */
final class DailyLimits
{
    public const int INVITATIONS_PER_DAY = 200;

    public const int UPLOADS_PER_DAY = 1_000;

    private const int ONE_DAY_IN_SECONDS = 86_400;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Sends an invitation if the company has invitations left today, and counts it once it's sent.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $send
     * @return TResult
     */
    public function sendingInvitation(callable $send): mixed
    {
        return $this->withinLimit('invitations', self::INVITATIONS_PER_DAY, $send, fn () => ['email' => __(
            'This company has sent :count invitations today, the most allowed in a day. Try again tomorrow.',
            ['count' => self::INVITATIONS_PER_DAY],
        )]);
    }

    /**
     * Stores an upload if the company has uploads left today, and counts it once it's stored. Without
     * a file nothing is stored, so saving a certificate's dates alone neither counts nor is refused.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $store
     * @return TResult
     */
    public function storingUpload(?UploadedFile $file, callable $store): mixed
    {
        if ($file === null) {
            return $store();
        }

        return $this->withinLimit('uploads', self::UPLOADS_PER_DAY, $store, fn () => ['file' => __(
            'This company has uploaded :count files today, the most allowed in a day. Try again tomorrow.',
            ['count' => self::UPLOADS_PER_DAY],
        )]);
    }

    /**
     * Two requests at the same moment may both pass the check just under the limit; the per-minute
     * limits on the routes keep that overshoot small.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @param  callable(): array<string, string>  $refusal
     * @return TResult
     */
    private function withinLimit(string $counter, int $perDay, callable $work, callable $refusal): mixed
    {
        $key = $this->key($counter);

        if (RateLimiter::tooManyAttempts($key, $perDay)) {
            throw ValidationException::withMessages($refusal());
        }

        $result = $work();
        RateLimiter::hit($key, self::ONE_DAY_IN_SECONDS);

        return $result;
    }

    /**
     * By the company's id, which never changes, unlike its address.
     */
    private function key(string $counter): string
    {
        $companyId = $this->currentCompany->id()
            ?? throw new LogicException('Daily limits only apply inside a company.');

        return "company-{$counter}|{$companyId}";
    }
}
