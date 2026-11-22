<?php

namespace App\Modules\Srm;

use App\Companies\CurrentCompany;
use App\Companies\DemoData;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Actions\AddStartingLists;
use App\Modules\Srm\Console\Commands\RenewContracts;
use App\Modules\Srm\Console\Commands\SendCertificateReminders;
use App\Modules\Srm\Console\Commands\SendContractReminders;
use App\Modules\Srm\Dashboard\SupplierAttention;
use App\Modules\Srm\Demo\DemoSuppliers;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Notifications\CertificateExpiringNotifications;
use App\Modules\Srm\Notifications\ContractNeedsActionNotifications;
use App\Notifications\NotificationKinds;
use App\Onboarding\OnboardingSteps;
use App\Pages\PageSections;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the supplier relationship management module into the app: its routes, tables,
 * commands, timed jobs and its sections on the company's home page.
 */
class SrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->startNewCompanies();
        $this->addDemoData();
        $this->addHomeSections();
        $this->addOnboardingSteps();
        $this->registerNotificationKinds();
        $this->registerRoutes();
        $this->limitSupplierImports();
        $this->registerCommands();
    }

    private function startNewCompanies(): void
    {
        Company::created(function (Company $company): void {
            $this->app->make(CurrentCompany::class)->runAs(
                $company,
                fn () => $this->app->make(AddStartingLists::class)->handle(),
            );
        });
    }

    /** Made-up suppliers for a new demo company, before the modules that use them. */
    private function addDemoData(): void
    {
        $this->callAfterResolving(DemoData::class, function (DemoData $demoData): void {
            $demoData->add('srm_suppliers', 10, fn (Company $company, User $admin) => $this->app->make(DemoSuppliers::class)->fill($admin));
        });
    }

    /** Certificate and contract reminders addressed to a person arrive in Core's bell and summary email. */
    private function registerNotificationKinds(): void
    {
        $this->callAfterResolving(NotificationKinds::class, function (NotificationKinds $kinds): void {
            $kinds->register(new CertificateExpiringNotifications);
            $kinds->register(new ContractNeedsActionNotifications);
        });
    }

    /** The supplier dashboard's attention tiles and soonest dates, for every member: everyone can see suppliers. */
    private function addHomeSections(): void
    {
        $sections = $this->app->make(PageSections::class);

        // Deferred: the counts read every supplier, so the page opens first.
        $sections->add('home', 'srm_attention', 30, 'srm/SupplierAttention',
            fn (): bool => true,
            fn (User $user): array => SupplierAttention::for($user)->counts(),
            deferred: true);
        $sections->add('home', 'srm_upcoming', 40, 'srm/UpcomingDates',
            fn (): bool => true,
            fn (User $user): array => SupplierAttention::for($user)->upcoming(),
            deferred: true);
    }

    /** The supplier module's part of the getting-started checklist on Home. */
    private function addOnboardingSteps(): void
    {
        $this->callAfterResolving(OnboardingSteps::class, function (OnboardingSteps $steps): void {
            // Archived suppliers count: the company has started.
            $steps->add('add_suppliers', 10,
                title: fn (): string => __('Add your suppliers'),
                description: fn (): string => __('Import them from a spreadsheet, or add them one by one.'),
                link: fn (): string => route('suppliers.import.create', absolute: false),
                done: fn (): bool => Supplier::query()->exists());
            // Without certificate types no certificates can be recorded, so certificate dates and risk stay empty.
            $steps->add('choose_certificates', 20,
                title: fn (): string => __('Choose the certificates you track'),
                description: fn (): string => __('List the certificates you ask suppliers for, and mark the ones every supplier must have.'),
                link: fn (): string => route('admin.certificate-types.index', absolute: false),
                done: fn (): bool => CertificateType::query()->exists());
            $steps->add('choose_reminder_email', 30,
                title: fn (): string => __('Choose where reminders go'),
                description: fn (): string => __('Certificate reminders for suppliers without a responsible buyer go to this address.'),
                link: fn (): string => route('admin.settings.edit', absolute: false),
                done: fn (): bool => Setting::query()
                    ->whereKey(SettingKey::ReminderEmail->value)
                    ->whereNotNull('value')
                    ->where('value', '!=', '')
                    ->exists());
        });
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'company'])
            ->prefix('{company}')
            ->where(['company' => Company::routePattern()])
            ->group(__DIR__.'/routes/web.php');
        Route::middleware('api')->prefix('api')->group(__DIR__.'/routes/api.php');
    }

    /** Reading a file of thousands of rows is heavy work, so each person may preview or import 20 files a minute. */
    private function limitSupplierImports(): void
    {
        RateLimiter::for('supplier-import', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id));
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([RenewContracts::class, SendContractReminders::class, SendCertificateReminders::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('app:renew-contracts')
                ->dailyAt('05:30')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('app:send-contract-reminders')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('app:send-certificate-reminders')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
