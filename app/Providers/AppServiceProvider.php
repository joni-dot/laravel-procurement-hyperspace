<?php

namespace App\Providers;

use App\Companies\DemoData;
use App\Http\CheckRouteIds;
use App\Http\TrustedProxies;
use App\Models\Company;
use App\Models\User;
use App\Notifications\NotificationKinds;
use App\Onboarding\OnboardingChecklist;
use App\Onboarding\OnboardingSteps;
use App\Pages\PageSections;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Modules add their sections while booting; every page reads the same list.
        $this->app->singleton(PageSections::class);
        // Modules register their kinds of notification while booting.
        $this->app->singleton(NotificationKinds::class);
        // Modules add what they put into a new demo company while booting.
        $this->app->singleton(DemoData::class);
        // Modules add the steps about their own data to the getting-started checklist while booting.
        $this->app->singleton(OnboardingSteps::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        TrustedProxies::applyConfiguration();
        Route::substituteImplicitBindingsUsing(new CheckRouteIds);
        $this->addOnboardingChecklist();
    }

    /**
     * The getting-started checklist at the top of Home, Core's own step in it, and the link at the bottom
     * that shows it again after an admin hid it.
     */
    private function addOnboardingChecklist(): void
    {
        $this->callAfterResolving(OnboardingSteps::class, function (OnboardingSteps $steps): void {
            $steps->add('invite_colleagues', 50,
                title: fn (): string => __('Invite your colleagues'),
                description: fn (): string => __('Add the people who work with you, as buyers, viewers or admins.'),
                link: fn (): string => route('admin.users.index', absolute: false),
                // Anyone besides the first admin, invited or joined; a withdrawn invitation doesn't count.
                done: fn (Company $company): bool => $company->memberships()->whereNull('deactivated_at')->count() > 1);
        });

        $sections = $this->app->make(PageSections::class);
        $checklist = fn (): OnboardingChecklist => $this->app->make(OnboardingChecklist::class);

        // Not deferred: its few small queries cost less than the page jumping when it pops in at the top.
        $sections->add('home', 'onboarding_checklist', 0, 'onboarding/Checklist',
            fn (User $user): bool => $checklist()->shows($user),
            fn (): array => $checklist()->data());
        $sections->add('home', 'onboarding_show_again', 1000, 'onboarding/ShowChecklist',
            fn (User $user): bool => $checklist()->offersToShowAgain($user),
            fn (): array => $checklist()->summary());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Emails show names and titles people typed. Escaping Markdown's link brackets in them stops a typed
        // "[Sign in](https://evil.example)" from becoming a real link in an email sent from our address.
        Markdown::withSecuredEncoding();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Length and a check against known password leaks, as OWASP ASVS and NIST advise. Demands for
        // capitals, digits and symbols are left out on purpose: they push people to predictable
        // passwords ("Summer2026!") without making them harder to guess.
        // Every server people use gets the same rule, a staging server too; only local development and
        // the automated tests keep Laravel's shorter default, so their fixed test passwords keep working.
        Password::defaults(fn (): ?Password => app()->environment('local', 'testing')
            ? null
            : Password::min(12)->uncompromised(),
        );
    }
}
