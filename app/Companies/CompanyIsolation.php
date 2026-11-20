<?php

namespace App\Companies;

use Illuminate\Support\Facades\DB;

/**
 * Keeps each company's rows apart inside PostgreSQL, as a second lock behind the app's own checks.
 *
 * Every company table gets a company_id column and a row-level security policy: the database only
 * shows and accepts rows of the company the session is working for (the app.company_id setting,
 * set by CurrentCompany). A session with no company sees nothing. Links between company tables
 * include company_id, so a row can't point to another company's row either.
 *
 * Used by migrations only; table and column names are fixed strings there.
 */
final class CompanyIsolation
{
    public const string SETTING = 'app.company_id';

    /** The session's company, or null when none is set (then every comparison fails). */
    private const string CURRENT_COMPANY = "NULLIF(current_setting('app.company_id', true), '')::bigint";

    /**
     * Adds company_id, filled from the session's company when an insert doesn't give one, so rows
     * inserted without a model (pivot rows, for example) get it too.
     */
    public static function addCompanyColumn(string $table): void
    {
        DB::statement(sprintf(
            'ALTER TABLE %1$s ADD COLUMN company_id bigint NOT NULL DEFAULT %2$s CONSTRAINT %1$s_company_id_foreign REFERENCES companies (id) ON DELETE RESTRICT',
            $table,
            self::CURRENT_COMPANY,
        ));
    }

    /**
     * Turns on the row-level security policy. FORCE makes it apply to the table's owner as well,
     * which matters on hosts where the owner isn't a superuser.
     */
    public static function enable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf(
            'CREATE POLICY company_isolation ON %1$s USING (company_id = %2$s) WITH CHECK (company_id = %2$s)',
            $table,
            self::CURRENT_COMPANY,
        ));
    }

    public static function disable(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS company_isolation ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    /** Lets other tables link to this one within a company (they reference company_id and id together). */
    public static function allowLinksTo(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_company_id_id_unique UNIQUE (company_id, id)");
    }

    /**
     * Replaces a plain foreign key with one that includes company_id, so the linked row must belong
     * to the same company.
     */
    public static function linkWithinCompany(string $table, string $column, string $parent, string $onDelete = 'CASCADE'): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_foreign");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_company_foreign FOREIGN KEY (company_id, {$column}) REFERENCES {$parent} (company_id, id) ON DELETE {$onDelete}");
    }

    /** Replaces a foreign key to users with one to the company's memberships: the person must belong to the company. */
    public static function linkToMember(string $table, string $column): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_foreign");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_member_foreign FOREIGN KEY (company_id, {$column}) REFERENCES memberships (company_id, user_id) ON DELETE RESTRICT");
    }

    /** Undoes addCompanyColumn and everything built on it (policy, company links and indexes). */
    public static function removeCompanyColumn(string $table): void
    {
        self::disable($table);
        DB::statement("ALTER TABLE {$table} DROP COLUMN company_id CASCADE");
    }
}
