<?php

namespace App\Modules\Ppr\Actions\Packages;

use App\Models\User;
use App\Modules\Ppr\Models\Package;

/**
 * Marks a package as high risk by hand, or replaces the reason. The package turns red (see App\Modules\Ppr\Risk\PackageRisk).
 */
class FlagPackageRisk
{
    public function handle(Package $package, User $flaggedBy, string $reason): void
    {
        $package->forceFill([
            'risk_flag_reason' => $reason,
            'risk_flagged_by_id' => $flaggedBy->id,
            'risk_flagged_at' => now(),
        ])->save();
    }
}
