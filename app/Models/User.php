<?php

namespace App\Models;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Notifications\ResetPasswordLink;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property-read Role|null $role the role in the current company; null outside a company or without an active membership there
 * @property bool $is_platform_admin
 * @property int|null $last_company_id
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Active memberships already looked up, by company id, so permission checks in one request
     * don't query again.
     *
     * @var array<int, Membership|null>
     */
    private array $membershipsByCompany = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * People who can be made responsible for suppliers and contracts.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeBuyersAndAdmins(Builder $query): void
    {
        $query->whereNull('deactivated_at')->whereHas('memberships', fn (Builder $memberships) => $memberships
            ->where('company_id', app(CurrentCompany::class)->id() ?? 0)
            ->whereIn('role', [Role::Admin, Role::Buyer])
            ->active());
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The role in the company the request works in. Every permission check goes through this, so
     * outside a company, or without an active membership there, nobody has any role.
     *
     * @return Attribute<Role|null, never>
     */
    protected function role(): Attribute
    {
        // Not cached on the model: Eloquent would otherwise keep the first role it read (enums are
        // objects) and carry it into another company.
        return Attribute::get(fn (): ?Role => $this->activeMembershipIn(app(CurrentCompany::class)->id())?->role)
            ->withoutObjectCaching();
    }

    public function activeMembershipIn(?int $companyId): ?Membership
    {
        if ($companyId === null) {
            return null;
        }

        if (! array_key_exists($companyId, $this->membershipsByCompany)) {
            $this->membershipsByCompany[$companyId] = $this->memberships()->where('company_id', $companyId)->active()->first();
        }

        return $this->membershipsByCompany[$companyId];
    }

    /**
     * The company to open when the person arrives without naming one: the last one they used, if
     * they can still open it, otherwise the first by name.
     */
    public function homeCompany(): ?Company
    {
        $companies = Company::query()
            ->whereNull('suspended_at')
            ->whereHas('memberships', fn (Builder $memberships) => $memberships->where('user_id', $this->id)->active())
            ->orderByRaw('id = ? desc', [$this->last_company_id ?? 0])
            ->orderBy('name');

        return $companies->first();
    }

    /**
     * Forgets the memberships looked up so far, after one of them changed.
     */
    public function forgetMemberships(): void
    {
        $this->membershipsByCompany = [];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function status(): UserStatus
    {
        return match (true) {
            $this->isDeactivated() => UserStatus::Deactivated,
            $this->password === null => UserStatus::Invited,
            default => UserStatus::Active,
        };
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }
}
