<?php

namespace App\Modules\Ppr\Notifications;

use App\Modules\Ppr\Enums\PackageAssignment;
use App\Modules\Ppr\Models\Package;
use App\Notifications\NotificationMessage;

/**
 * "You are now the owner (or responsible buyer) of this package."
 */
class PackageAssigned extends PackageNotification
{
    public function __construct(Package $package, private readonly PackageAssignment $assignment)
    {
        parent::__construct($package);
    }

    public static function kind(): string
    {
        return 'package_assigned';
    }

    /**
     * @return array{role: string}
     */
    protected function params(): array
    {
        return ['role' => $this->assignment->value];
    }

    public static function message(array $data, string $projectName): NotificationMessage
    {
        $replacements = ['package' => self::packageName($data)];

        return ($data['params']['role'] ?? null) === PackageAssignment::Owner->value
            ? NotificationMessage::make(__('You are now the owner of :package.'), $replacements)
            : NotificationMessage::make(__('You are now the responsible buyer of :package.'), $replacements);
    }
}
