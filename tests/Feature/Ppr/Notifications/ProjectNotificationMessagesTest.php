<?php

namespace Tests\Feature\Ppr\Notifications;

use App\Modules\Ppr\Notifications\DateComingUp;
use App\Modules\Ppr\Notifications\DateOverdue;
use App\Modules\Ppr\Notifications\PackageAssigned;
use App\Modules\Ppr\Notifications\RiskChangesSummary;
use App\Modules\Ppr\Notifications\RiskLevelChanged;
use App\Notifications\NotificationMessage;
use Tests\TestCase;

/**
 * The sentences of the project notifications, read on the notifications page and in the summary email.
 */
class ProjectNotificationMessagesTest extends TestCase
{
    private const array PACKAGE = ['package_id' => 7, 'package_number' => 'HC-0001', 'package_title' => 'Hoist motor'];

    public function test_assignments()
    {
        $this->assertSame('You are now the owner of HC-0001 Hoist motor.', $this->plain(PackageAssigned::message($this->package(['role' => 'owner']), 'Harbour crane')));
        $this->assertSame('You are now the responsible buyer of HC-0001 Hoist motor.', $this->plain(PackageAssigned::message($this->package(['role' => 'buyer']), 'Harbour crane')));
    }

    public function test_coming_dates_say_today_tomorrow_or_how_many_days()
    {
        $coming = fn (int $daysLeft) => DateComingUp::message($this->package(['milestone' => 'Order placed', 'date' => '2027-03-13', 'days_left' => $daysLeft]), 'Harbour crane');

        $this->assertSame(['HC-0001 Hoist motor: Order placed is due today (', ['date' => '2027-03-13'], ').'], $coming(0)->parts);
        $this->assertSame('HC-0001 Hoist motor: Order placed is due tomorrow (2027-03-13).', $this->plain($coming(1)));
        $this->assertSame('HC-0001 Hoist motor: Order placed is due in 3 days (2027-03-13).', $this->plain($coming(3)));
    }

    public function test_overdue_dates()
    {
        $message = DateOverdue::message($this->package(['milestone' => 'Order placed', 'date' => '2027-03-13']), 'Harbour crane');

        $this->assertSame("HC-0001 Hoist motor: Order placed was due on 2027-03-13 and isn't marked done.", $this->plain($message));
    }

    public function test_risk_changes_with_their_reasons()
    {
        $changed = fn (array $params) => RiskLevelChanged::message($this->package([
            'from' => 'none', 'to' => 'red', 'reasons' => [], 'float_days' => null, 'overdue_milestone' => null, 'overdue_date' => null, ...$params,
        ]), 'Harbour crane');

        $this->assertSame(
            ['HC-0001 Hoist motor: risk is now Red (was No risk): Overdue: Order placed was due on ', ['date' => '2027-03-10'], '; Late: expected 3 days after the need date.'],
            $changed(['reasons' => ['overdue', 'late'], 'float_days' => -3, 'overdue_milestone' => 'Order placed', 'overdue_date' => '2027-03-10'])->parts,
        );
        $this->assertSame(
            'HC-0001 Hoist motor: risk is now Amber (was Red): Short on time: 1 day to spare; No responsible buyer; The chosen supplier is high risk or archived.',
            $this->plain($changed(['from' => 'red', 'to' => 'amber', 'reasons' => ['late', 'no_buyer', 'supplier_at_risk'], 'float_days' => 1])),
        );
        $this->assertSame(
            'HC-0001 Hoist motor: risk is now Red (was Amber): Flagged as high risk; Late: expected 1 day after the need date.',
            $this->plain($changed(['from' => 'amber', 'reasons' => ['manual_flag', 'late'], 'float_days' => -1])),
        );
        $this->assertSame('HC-0001 Hoist motor is no longer at risk (it was Red).', $this->plain($changed(['from' => 'red', 'to' => 'none'])));
    }

    public function test_the_summary_names_the_project()
    {
        $message = RiskChangesSummary::message(['params' => ['changed' => 14, 'red' => 5, 'amber' => 6, 'none' => 3]], 'Harbour crane');

        $this->assertSame('14 packages in Harbour crane changed risk level: 5 now red, 6 now amber, 3 no longer at risk.', $this->plain($message));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{package_id: int, package_number: string, package_title: string, params: array<string, mixed>}
     */
    private function package(array $params): array
    {
        return [...self::PACKAGE, 'params' => $params];
    }

    private function plain(NotificationMessage $message): string
    {
        return $message->plain();
    }
}
