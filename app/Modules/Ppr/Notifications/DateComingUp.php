<?php

namespace App\Modules\Ppr\Notifications;

use App\Modules\Ppr\Models\Package;
use App\Notifications\NotificationMessage;

/**
 * "Order placed is due in 3 days." Sent once per milestone and due date (the forecast, else the planned date), when it comes
 * within the person's own notification period.
 */
class DateComingUp extends PackageNotification
{
    public function __construct(
        Package $package,
        private readonly string $milestoneName,
        private readonly string $expectedDate,
        private readonly int $daysLeft,
    ) {
        parent::__construct($package);
    }

    public static function kind(): string
    {
        return 'date_coming_up';
    }

    /**
     * @return array{milestone: string, date: string, days_left: int}
     */
    protected function params(): array
    {
        return ['milestone' => $this->milestoneName, 'date' => $this->expectedDate, 'days_left' => $this->daysLeft];
    }

    public static function message(array $data, string $projectName): NotificationMessage
    {
        $params = $data['params'];
        $daysLeft = (int) $params['days_left'];
        $replacements = [
            'package' => self::packageName($data),
            'milestone' => (string) $params['milestone'],
            'date' => NotificationMessage::date((string) $params['date']),
            'count' => $daysLeft,
        ];

        return match ($daysLeft) {
            0 => NotificationMessage::make(__(':package: :milestone is due today (:date).'), $replacements),
            1 => NotificationMessage::make(__(':package: :milestone is due tomorrow (:date).'), $replacements),
            default => NotificationMessage::make(__(':package: :milestone is due in :count days (:date).'), $replacements),
        };
    }
}
