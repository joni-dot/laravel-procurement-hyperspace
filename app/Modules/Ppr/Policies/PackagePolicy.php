<?php

namespace App\Modules\Ppr\Policies;

use App\Models\User;
use App\Modules\Ppr\Access\ProjectRoles;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;

/**
 * Everyone in the project can look at its packages (see ProjectPolicy::view).
 * Admins, project managers and buyers add and edit all of them; package owners edit their own.
 */
class PackagePolicy
{
    public function __construct(private readonly ProjectRoles $projectRoles) {}

    /**
     * Add packages and sub-packages, and choose who owns and buys a package.
     */
    public function create(User $user, Project $project): bool
    {
        return $user->isAdmin() || in_array($this->projectRoles->of($user, $project), ProjectRole::managingPackages(), true);
    }

    public function update(User $user, Package $package): bool
    {
        return $this->updateOwnedBy($user, $package->project, $package->owner_id);
    }

    /**
     * Change or clear a planned date that is already set. Planned dates are the original plan, so only
     * the people running the project move them; anyone who may update the package can set a missing one.
     */
    public function changePlannedDates(User $user, Project $project): bool
    {
        return $user->isAdmin() || $this->projectRoles->of($user, $project) === ProjectRole::ProjectManager;
    }

    /**
     * The rule behind update(), for lists that check thousands of packages without loading each one.
     */
    public function updateOwnedBy(User $user, Project $project, ?int $ownerId): bool
    {
        return $this->create($user, $project)
            || ($ownerId === $user->id && $this->projectRoles->of($user, $project) === ProjectRole::PackageOwner);
    }
}
