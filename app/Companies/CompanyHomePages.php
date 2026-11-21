<?php

namespace App\Companies;

use App\Models\User;
use Closure;
use LogicException;

/**
 * Decides which page a company opens on. Each module offers its overview with a priority and a
 * rule for whom it suits; the first that suits the person wins. Core knows no module's pages.
 */
final class CompanyHomePages
{
    /** @var array<int, list<Closure(User): ?string>> route-name choosers by priority, lowest first */
    private array $choosers = [];

    /**
     * @param  Closure(User): ?string  $routeFor  the route name of the page, or null when it doesn't suit the person
     */
    public function offer(int $priority, Closure $routeFor): void
    {
        $this->choosers[$priority][] = $routeFor;
        ksort($this->choosers);
    }

    /**
     * The route name of the page the person should land on. Runs inside the company, so choosers
     * may read the company's data.
     */
    public function routeFor(User $user): string
    {
        foreach ($this->choosers as $choosers) {
            foreach ($choosers as $routeFor) {
                $route = $routeFor($user);

                if ($route !== null) {
                    return $route;
                }
            }
        }

        throw new LogicException('No module offers a home page for this person.');
    }
}
