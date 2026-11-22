<?php

namespace App\Onboarding;

use App\Models\Company;
use Closure;

/**
 * One step of the getting-started checklist, added by the module that owns its data (OnboardingSteps).
 */
final readonly class OnboardingStep
{
    /**
     * @param  Closure(): string  $title
     * @param  Closure(): string  $description
     * @param  Closure(): string  $link
     * @param  Closure(Company): bool  $done
     */
    public function __construct(
        public string $key,
        public int $order,
        public Closure $title,
        public Closure $description,
        public Closure $link,
        public Closure $done,
    ) {}
}
