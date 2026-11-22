<?php

namespace App\Onboarding;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use LogicException;

/**
 * The getting-started checklist on the open company's Home page. Only its admins get it, never in a demo
 * company, and only until every step is done: the first admin Home that finds them all done records it, and
 * the checklist stays away for good after that. An admin can hide it for the whole company; while it is
 * hidden and unfinished, admins get a link at the bottom of Home to show it again.
 */
final readonly class OnboardingChecklist
{
    public function __construct(
        private Request $request,
        private CurrentCompany $currentCompany,
        private OnboardingSteps $steps,
    ) {}

    public function shows(User $user): bool
    {
        return $this->concerns($user) && ! $this->isHidden() && ! $this->isComplete();
    }

    public function offersToShowAgain(User $user): bool
    {
        return $this->concerns($user) && $this->isHidden() && ! $this->isComplete();
    }

    /**
     * @return array{steps: list<array{key: string, title: string, description: string, url: string, done: bool}>, doneCount: int}
     */
    public function data(): array
    {
        $steps = $this->progress();

        return ['steps' => $steps, 'doneCount' => count(array_filter(array_column($steps, 'done')))];
    }

    /**
     * @return array{doneCount: int, total: int}
     */
    public function summary(): array
    {
        $steps = $this->progress();

        return ['doneCount' => count(array_filter(array_column($steps, 'done'))), 'total' => count($steps)];
    }

    // Checked before any step's query, so nobody else's Home pays for the checklist.
    private function concerns(User $user): bool
    {
        return $user->isAdmin() && ! $this->company()->is_demo && $this->company()->onboarding_completed_at === null;
    }

    private function isHidden(): bool
    {
        return $this->company()->onboarding_hidden_at !== null;
    }

    private function isComplete(): bool
    {
        $isComplete = ! in_array(false, array_column($this->progress(), 'done'), true);

        if ($isComplete) {
            $this->company()->forceFill(['onboarding_completed_at' => now()])->save();
        }

        return $isComplete;
    }

    /**
     * @return list<array{key: string, title: string, description: string, url: string, done: bool}>
     */
    private function progress(): array
    {
        // On the request, so the steps are checked once per page and the result never outlives the request.
        $key = self::class.':'.$this->company()->id;

        if (! $this->request->attributes->has($key)) {
            $this->request->attributes->set($key, $this->steps->progressOf($this->company()));
        }

        return $this->request->attributes->get($key);
    }

    private function company(): Company
    {
        return $this->currentCompany->get() ?? throw new LogicException('The getting-started checklist belongs to an open company.');
    }
}
