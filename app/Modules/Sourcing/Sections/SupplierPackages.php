<?php

namespace App\Modules\Sourcing\Sections;

use App\Models\User;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * The project packages a supplier is on, for the section on the supplier's page. Only packages in projects
 * the person can see (admins: all), read in one query.
 */
class SupplierPackages
{
    public const int MOST_SHOWN = 100;

    /**
     * @return array{packages: list<array<string, mixed>>, total: int}
     */
    public function for(User $user, Supplier $supplier): array
    {
        $rows = PackageSupplier::query()
            ->join('packages', 'packages.id', '=', 'package_suppliers.package_id')
            ->join('projects', 'projects.id', '=', 'packages.project_id')
            ->where('package_suppliers.supplier_id', $supplier->id)
            ->whereIn('packages.project_id', Project::query()->visibleTo($user)->select('projects.id'))
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->orderBy('packages.number_path')
            ->limit(self::MOST_SHOWN)
            ->select([
                'projects.id as project_id', 'projects.name as project_name', 'projects.code as project_code',
                'packages.id as package_id', 'packages.number', 'packages.title', 'packages.status as package_status',
                'package_suppliers.status',
                // Counted before the limit, so the total needs no query of its own.
                DB::raw('count(*) over () as total'),
            ])
            ->toBase()
            ->get();

        return [
            'packages' => array_values($rows->map(function (object $row): array {
                $status = QuoteStatus::from($row->status);

                return [
                    'projectId' => $row->project_id,
                    'projectName' => $row->project_name,
                    'projectCode' => $row->project_code,
                    'packageId' => $row->package_id,
                    'number' => $row->number,
                    'title' => $row->title,
                    'packageStatus' => PackageStatus::from($row->package_status),
                    'status' => $status,
                    'statusLabel' => $status->label(),
                ];
            })->all()),
            'total' => (int) ($rows->first()->total ?? 0),
        ];
    }
}
