<?php

namespace App\Models;

use App\Enums\Role;
use App\Rules\CompanySlug as CompanySlugRule;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A customer of the service. Its data is kept apart from every other company's.
 *
 * @property int $id
 * @property string $name
 * @property string $slug the company's part of the address: /{slug}/...
 * @property CarbonImmutable|null $suspended_at
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** What an address may look like: lowercase letters, digits and inner dashes, at most 40 characters. */
    public const string SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?';

    /**
     * The {company} part of a route: an address that isn't reserved. Pages outside companies, such as
     * /settings/notifications, then never match a company route, whatever order the routes are
     * registered in.
     */
    public static function routePattern(): string
    {
        $reserved = array_filter(CompanySlugRule::RESERVED, fn (string $word) => preg_match('/^'.self::SLUG_PATTERN.'$/D', $word) === 1);

        // Not followed by another address character, so "settings" is refused but "settings-team" isn't.
        return '(?!(?:'.implode('|', array_map(fn (string $word) => preg_quote($word, '#'), $reserved)).')(?![a-z0-9-]))'.self::SLUG_PATTERN;
    }

    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        // An address that ever belonged to another company is never reused, so old links can't
        // lead into the wrong company.
        static::saving(function (self $company): void {
            if ($company->isDirty('slug') && self::slugBelongsToAnotherCompany($company->slug, $company)) {
                throw new LogicException("The address {$company->slug} belongs or belonged to another company.");
            }
        });

        static::saved(function (self $company): void {
            if ($company->wasChanged('slug') || $company->wasRecentlyCreated) {
                CompanySlug::query()->insertOrIgnore([
                    'slug' => $company->slug,
                    'company_id' => $company->id,
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Whether the address is, or was, used by a company other than the given one (any company when
     * none is given). A company may go back to one of its own old addresses.
     */
    public static function slugBelongsToAnotherCompany(string $slug, ?self $company = null): bool
    {
        return CompanySlug::query()
            ->whereKey($slug)
            ->when($company?->exists, fn ($query) => $query->where('company_id', '!=', $company?->id))
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The newest invitation to be an admin that hasn't been accepted or withdrawn.
     *
     * @return HasOne<Membership, $this>
     */
    public function pendingAdminInvitation(): HasOne
    {
        return $this->hasOne(Membership::class)->ofMany(
            ['invitation_sent_at' => 'max', 'id' => 'max'],
            fn ($memberships) => $memberships->pendingInvitation()->where('role', Role::Admin),
        );
    }

    public function hasActiveAdmin(): bool
    {
        return $this->memberships()->active()->where('role', Role::Admin)->exists();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Finds a company by its current address or one it had before.
     */
    public static function findBySlug(string $slug): ?self
    {
        return self::query()->where('slug', $slug)->first()
            ?? CompanySlug::query()->with('company')->find($slug)?->company;
    }
}
