<?php

namespace App\Modules\Ppr\Notifications;

use App\Modules\Ppr\Enums\PackageAssignment;
use App\Modules\Ppr\Models\Package;

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
}
