<?php

namespace App\Modules\Ppr\Notifications;

use App\Modules\Ppr\Models\Package;
use App\Notifications\NotificationMessage;

/**
 * "Order placed was due on 3 Nov and isn't marked done." Sent once per milestone and due date (the forecast, else the planned date).
 */
class DateOverdue extends PackageNotification
{
    public function __construct(
        Package $package,
        private readonly string $milestoneName,
        private readonly string $expectedDate,
    ) {
        parent::__construct($package);
    }

    public static function kind(): string
    {
        return 'date_overdue';
    }

    /**
     * @return array{milestone: string, date: string}
     */
    protected function params(): array
    {
        return ['milestone' => $this->milestoneName, 'date' => $this->expectedDate];
    }

    public static function message(array $data, string $projectName): NotificationMessage
    {
        return NotificationMessage::make(__(":package: :milestone was due on :date and isn't marked done."), [
            'package' => self::packageName($data),
            'milestone' => (string) $data['params']['milestone'],
            'date' => NotificationMessage::date((string) $data['params']['date']),
        ]);
    }
}
