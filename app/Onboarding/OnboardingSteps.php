<?php

namespace App\Onboarding;

use App\Models\Company;
use Closure;
use InvalidArgumentException;

/**
 * The steps of the getting-started checklist a new company's admins see on Home. Each module adds the
 * steps about its own data while booting, so Core knows none of them. A step only belongs here when the
 * app can tell from the company's data that it is done; nobody ticks steps by hand.
 */
final class OnboardingSteps
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /** @var array<string, OnboardingStep> */
    private array $steps = [];

    /**
     * @param  string  $key  unique, lower case with underscores, such as "add_suppliers"
     * @param  int  $order  lowest first
     * @param  Closure(): string  $title  such as fn () => __('Add your suppliers'): read in the person's language, and TranslationsTest finds the text
     * @param  Closure(): string  $description  one line on what to do and why
     * @param  Closure(): string  $link  where to do it, in the open company, such as fn () => route('suppliers.create', absolute: false)
     * @param  Closure(Company): bool  $done  whether the company has done it; one cheap query, as Home runs every step's check
     */
    public function add(string $key, int $order, Closure $title, Closure $description, Closure $link, Closure $done): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Onboarding step key [{$key}] must be lower case letters, digits and underscores.");
        }

        if (isset($this->steps[$key])) {
            throw new InvalidArgumentException("Onboarding step [{$key}] is already added.");
        }

        $this->steps[$key] = new OnboardingStep($key, $order, $title, $description, $link, $done);
    }

    /**
     * @return list<OnboardingStep> lowest order first
     */
    public function all(): array
    {
        $steps = array_values($this->steps);
        usort($steps, fn (OnboardingStep $a, OnboardingStep $b) => $a->order <=> $b->order);

        return $steps;
    }

    /**
     * Every step, in order, with whether the company has done it. Call it inside that company (CurrentCompany):
     * the links and the steps' queries use the open company.
     *
     * @return list<array{key: string, title: string, description: string, url: string, done: bool}>
     */
    public function progressOf(Company $company): array
    {
        return array_map(fn (OnboardingStep $step) => [
            'key' => $step->key,
            'title' => ($step->title)(),
            'description' => ($step->description)(),
            'url' => ($step->link)(),
            'done' => ($step->done)($company),
        ], $this->all());
    }
}
