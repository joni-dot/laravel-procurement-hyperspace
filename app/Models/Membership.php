<?php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's place in a company, with their role there. Until joined_at is set it is an invitation.
 * Memberships are deactivated, never deleted, so the person's history in the company stays intact.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property Role $role
 * @property string|null $invited_name
 * @property int|null $invited_by_user_id
 * @property string|null $invitation_token a hash; the email holds the token itself
 * @property CarbonImmutable|null $invitation_sent_at
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $deactivated_at
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'user_id', 'role', 'invited_name', 'invited_by_user_id', 'joined_at'];

    protected $hidden = ['invitation_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'invitation_sent_at' => 'datetime',
            'joined_at' => 'datetime',
            'deactivated_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Joined and not deactivated: the person can work in the company.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNotNull('joined_at')->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->joined_at !== null && $this->deactivated_at === null;
    }
}
