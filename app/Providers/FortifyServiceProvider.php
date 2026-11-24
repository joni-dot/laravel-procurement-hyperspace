<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\SignInVersion;
use App\Auth\StrongSignIn;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\PasswordResetFailedResponse;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Http\VisitorAddress;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public const int FAILED_SIGN_INS_PER_VISITOR_PER_MINUTE = 30;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetResponse::class, PasswordResetFailedResponse::class);
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        StrongSignIn::listen();
        SignInVersion::listen();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing($this->authenticate(...));
    }

    /**
     * Fortify's own password check takes at least 0.2 seconds, but checking a real password takes
     * longer, so an unknown address answered faster and told who has an account. A failed check
     * here always takes auth.timebox_duration; a correct password answers at once.
     *
     * Each failed check holds a server worker for that long, so failures are also limited per
     * visitor, whatever addresses they try (the `login` limiter counts per address and visitor).
     * Successful sign-ins don't count, so a whole office behind one address can still sign in.
     */
    private function authenticate(Request $request): ?User
    {
        $email = $request->input(Fortify::username());
        $password = $request->input('password');
        $failures = 'sign-in-failures|'.VisitorAddress::forLimits($request);

        if (RateLimiter::tooManyAttempts($failures, self::FAILED_SIGN_INS_PER_VISITOR_PER_MINUTE)) {
            event(new Lockout($request));

            throw ValidationException::withMessages([
                Fortify::username() => __('auth.throttle', [
                    'seconds' => $seconds = RateLimiter::availableIn($failures),
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        // Counted before the check, so requests sent at the same time can't all slip under the limit.
        RateLimiter::hit($failures, 60);

        $user = $this->checkPassword($email, $password);

        if ($user !== null) {
            RateLimiter::decrement($failures);
        }

        return $user;
    }

    private function checkPassword(mixed $email, mixed $password): ?User
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($email, $password): ?User {
            $provider = Auth::guard('web')->getProvider();
            $user = is_string($email) && is_string($password)
                ? $provider->retrieveByCredentials([Fortify::username() => $email])
                : null;

            if (! $user instanceof User || ! $provider->validateCredentials($user, ['password' => $password])) {
                return null;
            }

            $provider->rehashPasswordIfRequired($user, ['password' => $password]);
            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration'));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        // Per account, and also per hour: five a minute alone would allow thousands of guesses a day.
        RateLimiter::for('two-factor', function (Request $request) {
            $account = (string) $request->session()->get('login.id');

            return [
                Limit::perMinute(5)->by('minute|'.$account),
                Limit::perHour(20)->by('hour|'.$account),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.VisitorAddress::forLimits($request));

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Per person (the account, or before sign-in the session), and a far higher limit per visitor
        // address: a visitor can start new sessions at will, but a whole office shares one address.
        RateLimiter::for('passkeys', function (Request $request) {
            $person = $request->user()?->getAuthIdentifier() ?? $request->session()->getId();

            return [
                Limit::perMinute(10)->by('person|'.$person),
                Limit::perMinute(100)->by('address|'.VisitorAddress::forLimits($request)),
            ];
        });
    }
}
