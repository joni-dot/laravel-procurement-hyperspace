<?php

namespace App\Modules\Ppr\Risk;

use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Scheduling\ProjectSchedules;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The risk of many packages of one project at once, with a constant number of queries however many
 * packages there are. The rules themselves are in PackageRisk.
 *
 * - ProjectRisks::for($project): every package of the project, three queries. For background jobs.
 * - ProjectRisks::fromRows($project, $rows, $schedules): when the rows and schedules are already
 *   loaded, as in the package list, so nothing is read twice. No queries.
 * - ProjectRisks::packageRows($project): the query for the rows, which a list can add its own columns to.
 */
final class ProjectRisks
{
    /**
     * Every package of the project.
     *
     * @return array<int, PackageRisk> per package id, in tree order
     */
    public static function for(Project $project): array
    {
        $rows = self::packageRows($project)->get();

        return self::fromRows($project, $rows, ProjectSchedules::for($project, $rows, now('UTC')->toDateString()));
    }

    /**
     * The project's packages in tree order, with what the rules need: id, parent_id, status, owner_id,
     * buyer_id, owner_deactivated_at, buyer_deactivated_at and risk_flag_reason. The owners and buyers are
     * joined as "owners" and "buyers", with their memberships in the company as "owner_memberships" and
     * "buyer_memberships": a person is deactivated when their account or their membership is. Other modules'
     * reasons come as one more column each (OutsideRiskReasons), so this stays one query.
     */
    public static function packageRows(Project $project): Builder
    {
        $rows = DB::table('packages')
            ->leftJoin('users as owners', 'owners.id', '=', 'packages.owner_id')
            ->leftJoin('users as buyers', 'buyers.id', '=', 'packages.buyer_id')
            ->leftJoin('memberships as owner_memberships', fn (JoinClause $join) => $join
                ->on('owner_memberships.company_id', '=', 'packages.company_id')
                ->on('owner_memberships.user_id', '=', 'packages.owner_id'))
            ->leftJoin('memberships as buyer_memberships', fn (JoinClause $join) => $join
                ->on('buyer_memberships.company_id', '=', 'packages.company_id')
                ->on('buyer_memberships.user_id', '=', 'packages.buyer_id'))
            ->where('packages.project_id', $project->id)
            ->orderBy('packages.number_path')
            ->select([
                'packages.id', 'packages.parent_id', 'packages.status', 'packages.owner_id', 'packages.buyer_id',
                'packages.risk_flag_reason',
                DB::raw('coalesce(owners.deactivated_at, owner_memberships.deactivated_at) as owner_deactivated_at'),
                DB::raw('coalesce(buyers.deactivated_at, buyer_memberships.deactivated_at) as buyer_deactivated_at'),
            ]);

        return app(OutsideRiskReasons::class)->addColumns($rows);
    }

    /**
     * @param  iterable<stdClass>  $rows  rows from packageRows(), in tree order
     * @param  ProjectSchedules  $schedules  the schedules of the same rows; overdue counts from their today
     * @return array<int, PackageRisk> per package id, in the order of the rows
     */
    public static function fromRows(Project $project, iterable $rows, ProjectSchedules $schedules): array
    {
        $thresholds = RiskThresholds::of($project);
        $outsideReasons = app(OutsideRiskReasons::class);
        $risks = [];

        foreach ($rows as $row) {
            $risks[$row->id] = PackageRisk::assess(new PackageRiskFacts(
                isFinished: $schedules->isFinished($row->id),
                schedule: $schedules->of($row->id),
                floatWithSubPackages: $schedules->floatWithSubPackages($row->id),
                isFlagged: $row->risk_flag_reason !== null,
                owner: AssignedPerson::of($row->owner_id, $row->owner_deactivated_at),
                buyer: AssignedPerson::of($row->buyer_id, $row->buyer_deactivated_at),
                outsideReasons: $outsideReasons->of($row),
            ), $thresholds, $schedules->today);
        }

        return $risks;
    }
}
