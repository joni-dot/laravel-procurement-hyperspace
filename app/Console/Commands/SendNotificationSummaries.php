<?php

namespace App\Console\Commands;

use App\Companies\EveryActiveCompany;
use App\Enums\NotificationEmail;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationKinds;
use App\Notifications\NotificationSummary;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily summary email. Goes through the companies one at a time: everyone there who wants the daily
 * summary and has unread notifications they may still see, which arrived since their last summary in that
 * company, gets one email naming the company and listing them. Each membership remembers up to when it was
 * summarized, so nothing is listed twice and nothing is skipped, even after a day the command didn't run.
 */
class SendNotificationSummaries extends Command
{
    protected $signature = 'notifications:send-summaries';

    protected $description = 'Email each person who wants it a summary of their new notifications, per company';

    /** Notifications younger than this wait for the next summary, so one saved by a slow transaction still running now is never skipped. */
    private const int SETTLE_SECONDS = 60;

    public function handle(EveryActiveCompany $everyActiveCompany, NotificationKinds $kinds): int
    {
        $succeeded = $everyActiveCompany->run(fn (Company $company) => $this->summarizeFor($company, $kinds));

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function summarizeFor(Company $company, NotificationKinds $kinds): void
    {
        $until = now()->subSeconds(self::SETTLE_SECONDS)->startOfSecond();
        $memberships = $this->membershipsWithNewNotifications($company, $until);
        User::loadMembershipsIn($company->id, $memberships->pluck('user'));
        $sent = 0;

        foreach ($memberships as $membership) {
            $sent += $this->summarize($company, $membership, $until, $kinds) ? 1 : 0;
        }

        // Everyone else starts from here too, so someone who turns the email on later only gets newer notifications.
        Membership::query()
            ->where('company_id', $company->id)
            ->where(fn (Builder $query) => $query->whereNull('notifications_summarized_at')->orWhere('notifications_summarized_at', '<', $until))
            ->update(['notifications_summarized_at' => $until]);

        $this->components->info("Sent {$sent} notification summary email(s) of {$company->name}.");
    }

    /**
     * Active members who want the daily summary and have unread notifications since their last one. Whether
     * they may still see them is checked per person afterwards; this only narrows the list with one query.
     *
     * @return Collection<int, Membership>
     */
    private function membershipsWithNewNotifications(Company $company, CarbonInterface $until): Collection
    {
        return Membership::query()
            ->where('company_id', $company->id)
            ->active()
            ->whereHas('user', fn (Builder $user) => $user
                ->whereNull('deactivated_at')
                ->where('notification_email', NotificationEmail::Daily->value))
            ->whereExists(fn (QueryBuilder $notifications) => $notifications
                ->selectRaw('1')
                ->from('notifications')
                ->where('notifications.company_id', $company->id)
                ->where('notifications.notifiable_type', (new User)->getMorphClass())
                ->whereColumn('notifications.notifiable_id', 'memberships.user_id')
                ->whereNull('notifications.read_at')
                ->where('notifications.created_at', '<=', $until)
                ->where(fn (QueryBuilder $since) => $since
                    ->whereNull('memberships.notifications_summarized_at')
                    ->orWhereColumn('notifications.created_at', '>', 'memberships.notifications_summarized_at')))
            ->with('user')
            ->get();
    }

    /**
     * @return bool whether an email went out
     */
    private function summarize(Company $company, Membership $membership, CarbonInterface $until, NotificationKinds $kinds): bool
    {
        $user = $membership->user;
        $news = Notification::query()
            ->visibleTo($user)
            ->whereNull('read_at')
            ->where('created_at', '<=', $until)
            ->when($membership->notifications_summarized_at, fn (Builder $query, CarbonInterface $since) => $query->where('created_at', '>', $since));

        $total = (clone $news)->count();
        $listed = $total === 0 ? collect() : $news->latest()->orderByDesc('id')->limit(NotificationSummary::MAX_LISTED)->get();

        DB::transaction(function () use ($company, $membership, $user, $until, $total, $listed, $kinds) {
            $membership->forceFill(['notifications_summarized_at' => $until])->save();

            if ($total > 0) {
                $user->notify(new NotificationSummary($company->name, $listed, $kinds->describe($listed), $total));
            }
        });

        return $total > 0;
    }
}
