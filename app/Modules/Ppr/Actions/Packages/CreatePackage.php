<?php

namespace App\Modules\Ppr\Actions\Packages;

use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectCategory;
use Illuminate\Support\Facades\DB;

/**
 * Adds a package with just a title and a category, so many can be added quickly.
 * The other details are filled in later.
 */
class CreatePackage
{
    public function __construct(private readonly AssignPackageNumber $assignPackageNumber) {}

    public function handle(Project $project, string $title, ?ProjectCategory $category, ?Package $parent = null): Package
    {
        return DB::transaction(function () use ($project, $title, $category, $parent) {
            $package = new Package(['title' => $title]);
            $package->project_id = $project->id;
            $package->category()->associate($category);

            $this->assignPackageNumber->handle($package, $project, $parent);

            $package->save();

            return $package;
        });
    }
}
