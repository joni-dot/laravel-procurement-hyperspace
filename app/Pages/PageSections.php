<?php

namespace App\Pages;

use App\Models\User;
use Closure;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Lets a module add a section to a page it doesn't own, such as the home page, or a project package's
 * page showing the suppliers asked for a quote. The page names a spot ("home", "ppr.package"), modules add
 * sections to it, and the page passes props() into its Inertia response. Neither knows the other's code.
 *
 * Each section is drawn by a Vue component in resources/js/sections/{component}.vue, which gets its data
 * as the page prop "section_{key}". A closure, so a section reloads alone: router.reload({ only: ['section_{key}'] }).
 * A deferred section arrives just after the page, with a placeholder meanwhile, so a slow one never holds the page up.
 */
final class PageSections
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /** @var array<string, array<string, array{order: int, component: string, shows: Closure, data: Closure, deferred: bool}>> */
    private array $sections = [];

    /**
     * @param  string  $page  the spot, such as "home" or "ppr.package"
     * @param  string  $key  unique on the page, lower case with underscores, such as "sourcing_candidates"
     * @param  int  $order  lowest first
     * @param  string  $component  the Vue component under resources/js/sections, such as "sourcing/PackageSuppliers"
     * @param  Closure(User, array<string, mixed>): bool  $shows  whether the person gets the section at all; keep it cheap
     * @param  Closure(User, array<string, mixed>): mixed  $data  the section's data, only read when it is shown
     */
    public function add(string $page, string $key, int $order, string $component, Closure $shows, Closure $data, bool $deferred = false): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Section key [{$key}] must be lower case letters, digits and underscores.");
        }

        if (isset($this->sections[$page][$key])) {
            throw new InvalidArgumentException("Section [{$key}] is already on page [{$page}].");
        }

        $this->sections[$page][$key] = compact('order', 'component', 'shows', 'data', 'deferred');
    }

    /**
     * The props a page adds to its response: "sections" (what to draw, in order) and each shown section's data.
     *
     * @param  array<string, mixed>  $context  what the page is about, such as ['project' => $project, 'package' => $package]
     * @return array<string, mixed>
     */
    public function props(string $page, User $user, array $context = []): array
    {
        $shown = array_filter($this->sections[$page] ?? [], fn (array $section) => ($section['shows'])($user, $context));
        uasort($shown, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $props = ['sections' => array_map(
            fn (string $key, array $section) => ['key' => $key, 'component' => $section['component']],
            array_keys($shown),
            $shown,
        )];

        foreach ($shown as $key => $section) {
            $data = fn () => ($section['data'])($user, $context);
            $props["section_{$key}"] = $section['deferred'] ? Inertia::defer($data, 'sections') : $data;
        }

        return $props;
    }
}
