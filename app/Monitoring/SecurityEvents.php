<?php

namespace App\Monitoring;

use App\Auth\SignInVersion;
use App\Companies\CurrentCompany;
use App\Events\MembershipDeactivated;
use App\Events\MembershipReactivated;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The security event log (docs/monitoring.md): one line per sign-in, account change, membership change,
 * platform action, API client change and refused request, so a break-in leaves a trace.
 *
 * Each line names people and records by id only, and never holds what someone typed: no email
 * addresses, passwords, codes, tokens or secrets. A failed sign-in names nobody, so the log reads the
 * same whether or not the address has an account. The request's address, route and id come from the
 * log context (AddRequestContext). Only ids already loaded are used: logging never queries.
 */
final class SecurityEvents
{
    public const string MESSAGE = 'Security event';

    /** How each sign-in route signs someone in. */
    private const array SIGN_IN_METHODS = [
        'login.store' => 'password',
        'two-factor.login.store' => 'two_factor',
        'passkey.login' => 'passkey',
        'invitation.store' => 'invitation',
    ];

    /** Events about the user they carry, and the name each is logged under. */
    private const array ACCOUNT_EVENTS = [
        PasswordReset::class => 'account.password_reset',
        PasswordUpdatedViaController::class => 'account.password_changed',
        TwoFactorAuthenticationEnabled::class => 'account.two_factor_enabled',
        TwoFactorAuthenticationConfirmed::class => 'account.two_factor_confirmed',
        TwoFactorAuthenticationDisabled::class => 'account.two_factor_disabled',
        RecoveryCodesGenerated::class => 'account.recovery_codes_regenerated',
        RecoveryCodeReplaced::class => 'auth.recovery_code_used',
        TwoFactorAuthenticationFailed::class => 'auth.two_factor_failed',
        PasskeyRegistered::class => 'account.passkey_registered',
        PasskeyDeleted::class => 'account.passkey_deleted',
    ];

    private const array FAILURES = ['auth.two_factor_failed'];

    /**
     * @return array<string, string|list<string>>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'signedIn',
            Failed::class => 'signInFailed',
            Lockout::class => 'lockedOut',
            Logout::class => 'signedOut',
            ...array_fill_keys(array_keys(self::ACCOUNT_EVENTS), 'accountEvent'),
            MembershipDeactivated::class => 'membershipDeactivated',
            MembershipReactivated::class => 'membershipReactivated',
            'eloquent.updated: '.Membership::class => 'membershipUpdated',
            'eloquent.created: '.Company::class => 'companyCreated',
            'eloquent.updated: '.Company::class => 'companyUpdated',
            'eloquent.created: '.ApiClient::class => 'apiClientCreated',
            'eloquent.updated: '.ApiClient::class => 'apiClientUpdated',
        ];
    }

    /**
     * Writes one line. The user is whoever this request signed in and the company the open one,
     * unless the event names others.
     *
     * @param  array<string, int|string|null>  $fields
     */
    public static function record(string $event, array $fields = [], string $level = 'info'): void
    {
        Log::log($level, self::MESSAGE, [
            'event' => $event,
            'user_id' => self::signedInUserId(),
            'company_id' => app(CurrentCompany::class)->id(),
            ...$fields,
        ]);
    }

    /**
     * Refused requests, from the exception handler: anything a policy refused, any other 403, a
     * non-admin turned away from the platform pages (they get 404 there), and rate limits. Renders
     * nothing, so the usual answer goes out.
     */
    public static function recordRefusedRequest(HttpExceptionInterface $exception, Request $request): null
    {
        $status = $exception->getStatusCode();
        $route = self::routeName($request);

        if ($status === 403 || $exception->getPrevious() instanceof AuthorizationException) {
            self::record('authorization.denied', ['status' => $status], 'warning');
        } elseif ($status === 404 && str_starts_with($route, 'platform.') && ! self::signedInPlatformAdmin()) {
            self::record('authorization.platform_denied', ['status' => $status], 'warning');
        } elseif ($status === 429 && array_key_exists($route, self::SIGN_IN_METHODS)) {
            self::record('auth.lockout', ['user_id' => self::twoFactorChallengedUserId($request, $route)], 'warning');
        } elseif ($status === 429) {
            self::record('request.rate_limited', [], 'warning');
        }

        return null;
    }

    public function signedIn(Login $event): void
    {
        self::record('auth.signed_in', [
            'user_id' => self::idOf($event->user->getAuthIdentifier()),
            'method' => self::SIGN_IN_METHODS[self::routeName(request())] ?? ($event->remember ? 'remember_me' : 'other'),
        ]);
    }

    /** Names nobody: a user id here would tell which addresses have an account. */
    public function signInFailed(): void
    {
        self::record('auth.sign_in_failed', ['user_id' => null], 'warning');
    }

    /** Sign-in's own limit (Fortify); a limit hit before it is logged by recordRefusedRequest. */
    public function lockedOut(): void
    {
        self::record('auth.lockout', ['user_id' => null], 'warning');
    }

    public function signedOut(Logout $event): void
    {
        self::record('auth.signed_out', ['user_id' => self::idOf($event->user->getAuthIdentifier())]);
    }

    public function accountEvent(object $event): void
    {
        if ($event instanceof TwoFactorAuthenticationDisabled && ! SignInVersion::endedTwoFactorInUse($event)) {
            return;
        }

        $name = self::ACCOUNT_EVENTS[$event::class];
        $user = property_exists($event, 'user') ? $event->user : null;
        $fields = ['user_id' => $user instanceof User ? $user->id : self::signedInUserId()];

        if ($event instanceof PasskeyRegistered || $event instanceof PasskeyDeleted) {
            $fields['passkey_id'] = self::idOf($event->passkey->getKey());
        }

        self::record($name, $fields, in_array($name, self::FAILURES, true) ? 'warning' : 'info');
    }

    public function membershipDeactivated(MembershipDeactivated $event): void
    {
        self::record('membership.deactivated', [...self::membershipFields($event->membership), 'user_id' => $event->changedBy->id]);
    }

    public function membershipReactivated(MembershipReactivated $event): void
    {
        self::record('membership.reactivated', [...self::membershipFields($event->membership), 'user_id' => $event->changedBy->id]);
    }

    public function membershipUpdated(Membership $membership): void
    {
        if ($membership->wasChanged('role')) {
            $oldRole = $membership->getOriginal('role');

            self::record('membership.role_changed', [
                ...self::membershipFields($membership),
                'old_role' => $oldRole?->value,
            ]);
        }

        // A new link was sent; the platform only ever invites a company's first admin.
        if ($membership->wasChanged('invitation_sent_at')) {
            $onPlatform = str_starts_with(self::routeName(request()), 'platform.');

            self::record($onPlatform ? 'platform.first_admin_invited' : 'membership.invited', self::membershipFields($membership));
        }

        if ($membership->wasChanged('joined_at') && $membership->joined_at !== null) {
            self::record('membership.joined', self::membershipFields($membership));
        }
    }

    public function companyCreated(Company $company): void
    {
        self::record('platform.company_created', ['company_id' => $company->id]);
    }

    public function companyUpdated(Company $company): void
    {
        if ($company->wasChanged('is_demo') && $company->is_demo) {
            self::record('platform.demo_company_created', ['company_id' => $company->id]);
        }

        if ($company->wasChanged('suspended_at')) {
            self::record($company->isSuspended() ? 'platform.company_suspended' : 'platform.company_reopened', ['company_id' => $company->id]);
        }
    }

    public function apiClientCreated(ApiClient $client): void
    {
        self::record('api_client.created', self::apiClientFields($client));
    }

    public function apiClientUpdated(ApiClient $client): void
    {
        if ($client->wasChanged('secret')) {
            self::record('api_client.secret_regenerated', self::apiClientFields($client));
        }

        if ($client->wasChanged('scopes')) {
            self::record('api_client.scopes_changed', self::apiClientFields($client));
        }

        if ($client->wasChanged('revoked') && $client->revoked) {
            self::record('api_client.revoked', self::apiClientFields($client));
        }
    }

    /**
     * The membership's company, not the open one: platform pages change companies without opening them.
     *
     * @return array<string, int|string|null>
     */
    private static function membershipFields(Membership $membership): array
    {
        return [
            'company_id' => $membership->company_id,
            'membership_id' => $membership->id,
            'member_user_id' => $membership->user_id,
            'role' => $membership->role->value,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function apiClientFields(ApiClient $client): array
    {
        return ['company_id' => $client->company_id, 'api_client_id' => $client->id];
    }

    private static function routeName(Request $request): string
    {
        $route = $request->route();

        return $route instanceof Route ? (string) $route->getName() : '';
    }

    /** Only if this request has already loaded the user: no query to find out. */
    private static function signedInUserId(): ?int
    {
        return Auth::guard()->hasUser() ? self::idOf(Auth::guard()->id()) : null;
    }

    private static function signedInPlatformAdmin(): bool
    {
        $user = Auth::guard()->hasUser() ? Auth::guard()->user() : null;

        return $user instanceof User && $user->is_platform_admin;
    }

    /**
     * Whoever is being asked for a two-factor code: their password was right, so naming them tells no
     * outsider anything, and someone guessing codes for them knows their password.
     */
    private static function twoFactorChallengedUserId(Request $request, string $route): ?int
    {
        return $route === 'two-factor.login.store' && $request->hasSession()
            ? self::idOf($request->session()->get('login.id'))
            : null;
    }

    private static function idOf(mixed $id): ?int
    {
        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }
}
