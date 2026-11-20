<?php

namespace App\Models;

use App\Companies\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Passport\Client;

/**
 * A system that reads supplier data through the API with the OAuth client credentials grant.
 *
 * A client belongs to one company and only ever sees that company's data. There is no company
 * scope on every query, because the token endpoint must find a client before any company is known;
 * the admin pages ask for the open company's clients explicitly instead.
 *
 * @property string $id
 * @property int $company_id
 * @property string $name
 * @property list<string> $scopes
 * @property int|null $responsible_user_id
 * @property bool $revoked
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 */
class ApiClient extends Client
{
    public const string GRANT_TYPE = 'client_credentials';

    /**
     * Saving the time on every call would add a write to each request, so it is refreshed at most this often.
     */
    private const int LAST_USED_PRECISION_MINUTES = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofCompany(Builder $query, Company $company): void
    {
        $query->where('company_id', $company->id);
    }

    /**
     * A client in the address is looked up only among the open company's clients, so another
     * company's client is "not found", the same as one that doesn't exist.
     *
     * @param  Model|Relation<*, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): BuilderContract
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), app(CurrentCompany::class)->id() ?? 0);
    }

    public function belongsToCurrentCompany(): bool
    {
        return $this->company_id === app(CurrentCompany::class)->id();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('revoked', false);
    }

    public function revokeTokens(): void
    {
        $this->tokens()->where('revoked', false)->update(['revoked' => true]);
    }

    public function recordUse(): void
    {
        if ($this->last_used_at?->gt(now()->subMinutes(self::LAST_USED_PRECISION_MINUTES))) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
