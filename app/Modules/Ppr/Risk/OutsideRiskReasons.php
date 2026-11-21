<?php

namespace App\Modules\Ppr\Risk;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Amber reasons that other modules know about, such as the supplier chosen for a package being high risk.
 * The projects module doesn't know suppliers, so the module that links them adds a check here. Each check is
 * one more column in ProjectRisks::packageRows(), so every list still reads its packages with one query.
 */
final class OutsideRiskReasons
{
    /** @var array<string, Closure(): Builder> per reason value */
    private array $checks = [];

    /**
     * @param  Closure(): Builder  $finds  a query that finds a row for the outer query's "packages.id" when the reason applies
     *                                     (an Eloquent query's toBase(), so its company scope stays)
     */
    public function add(RiskReason $reason, Closure $finds): void
    {
        $this->checks[$reason->value] = $finds;
    }

    public function addColumns(Builder $rows): Builder
    {
        foreach ($this->checks as $reason => $finds) {
            // 1 when the check finds a row, else null.
            $rows->selectSub($finds()->select(DB::raw('1'))->limit(1), self::column($reason));
        }

        return $rows;
    }

    /**
     * @param  stdClass  $row  a row of ProjectRisks::packageRows()
     * @return list<RiskReason> in the order of RiskReason's cases
     */
    public function of(stdClass $row): array
    {
        return array_values(array_filter(
            RiskReason::cases(),
            fn (RiskReason $reason) => isset($this->checks[$reason->value]) && (bool) ($row->{self::column($reason->value)} ?? false),
        ));
    }

    private static function column(string $reason): string
    {
        return "outside_{$reason}";
    }
}
