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
 * @property string|null $known_name what the company knows the person as while they aren't an active member: the name the inviter typed, or the name they had when deactivated
 * @property string|null $known_email the address the inviter typed, or the one they had when deactivated
 * @property int|null $invited_by_user_id
 * @property string|null $invitation_token a hash; the email holds the token itself
 * @property CarbonImmutable|null $invitation_sent_at
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $notifications_summarized_at up to when the daily summary email has covered the person's notifications here
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'user_id', 'role', 'known_name', 'known_email', 'invited_by_user_id', 'joined_at'];

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
            'notifications_summarized_at' => 'datetime',
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
     * The name the company knows the person by. Only while they're an active member does the
     * company see their account's own (current) name; before they join it sees what the inviter
     * typed, and after they leave the name they had when they were deactivated. The account
     * belongs to the person, and later changes to it are none of a former company's business.
     * Pages that name people on the company's records use the same rule in SQL: nameOnRecordsSql().
     */
    public function nameForCompany(): string
    {
        return $this->isActive()
            ? $this->user->name
            : ($this->known_name ?? $this->known_email ?? '');
    }

    /**
     * nameForCompany() for a person on the company's records (an owner, an author, a project
     * member), as SQL, so lists can read it in the query they already make. $memberships is the
     * person's membership in the company the rows belong to, joined on company_id as well as
     * user_id (memberships have no row-level security), and $users is their users row. Records
     * only name people who joined, so a missing known_name means the person was deactivated
     * before the company kept one, or has no membership here at all: their account's name is
     * all the company has.
     *
     * @param  literal-string  $memberships
     * @param  literal-string  $users
     * @return literal-string
     */
    public static function nameOnRecordsSql(string $memberships = 'memberships', string $users = 'users'): string
    {
        return self::liveWhileActiveSql($memberships, "{$users}.name", "coalesce({$memberships}.known_name, {$users}.name)");
    }

    /**
     * The address the company knows the person by, the same way as nameOnRecordsSql().
     *
     * @param  literal-string  $memberships
     * @param  literal-string  $users
     * @return literal-string
     */
    public static function emailOnRecordsSql(string $memberships = 'memberships', string $users = 'users'): string
    {
        return self::liveWhileActiveSql($memberships, "{$users}.email", "coalesce({$memberships}.known_email, {$users}.email)");
    }

    /**
     * @param  literal-string  $memberships
     * @param  literal-string  $live
     * @param  literal-string  $remembered
     * @return literal-string
     */
    private static function liveWhileActiveSql(string $memberships, string $live, string $remembered): string
    {
        return "case when {$memberships}.joined_at is not null and {$memberships}.deactivated_at is null then {$live} else {$remembered} end";
    }

    /**
     * Keeps the name and address the company knew, for when the person is no longer an active
     * member. Only joined members have account details the company has seen.
     */
    public function rememberWhatTheCompanyKnew(): void
    {
        if ($this->joined_at !== null) {
            $this->forceFill(['known_name' => $this->user->name, 'known_email' => $this->user->email]);
        }
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
