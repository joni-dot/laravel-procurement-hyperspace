<?php

namespace App\Models;

use App\Companies\CurrentCompany;
use App\Enums\MembershipStatus;
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
 * @property string|null $invited_name the name the inviter gave; the list shows it until the person joins
 * @property string|null $invited_email the address the inviter typed
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

    protected $fillable = ['company_id', 'user_id', 'role', 'invited_name', 'invited_email', 'invited_by_user_id', 'joined_at'];

    protected $hidden = ['invitation_token'];

    /**
     * The invitation link's token itself, set only on the object SendInvitation just used and never
     * stored. app:create-company prints the link for self-hosters who have no email set up.
     */
    public ?string $plainInvitationToken = null;

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
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Memberships have no row-level security, so an address can only ever reach a membership of
     * the company that is open; outside a company it reaches none.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::query()
            ->where('company_id', app(CurrentCompany::class)->id())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Invited, not accepted yet, and not withdrawn by deactivating it. The link may have expired.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pendingInvitation(Builder $query): void
    {
        $query->whereNull('joined_at')->whereNull('deactivated_at');
    }

    /**
     * A pending invitation whose link still works.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function openInvitation(Builder $query): void
    {
        $query->pendingInvitation()
            ->whereNotNull('invitation_token')
            ->where('invitation_sent_at', '>', now()->subMinutes(self::invitationLifetimeInMinutes()));
    }

    public static function invitationLifetimeInMinutes(): int
    {
        return (int) config('auth.invitations.expire');
    }

    public function isOpenInvitation(): bool
    {
        return $this->joined_at === null
            && $this->deactivated_at === null
            && $this->invitation_token !== null
            && $this->invitation_sent_at?->addMinutes(self::invitationLifetimeInMinutes())->isFuture() === true;
    }

    /**
     * The name the company knows the person by: their own once they've joined, before that only
     * what the inviter typed. The account's name isn't the company's to see until then.
     */
    public function nameForCompany(): string
    {
        return $this->joined_at !== null
            ? $this->user->name
            : ($this->invited_name ?? $this->invited_email ?? '');
    }

    public function status(): MembershipStatus
    {
        return match (true) {
            $this->deactivated_at !== null => MembershipStatus::Deactivated,
            $this->joined_at !== null => MembershipStatus::Active,
            $this->isOpenInvitation() => MembershipStatus::Invited,
            default => MembershipStatus::InvitationExpired,
        };
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
