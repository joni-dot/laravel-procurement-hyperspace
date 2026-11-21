<?php

namespace App\Providers;

use App\Notifications\NotificationKinds;
use App\Pages\PageSections;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
