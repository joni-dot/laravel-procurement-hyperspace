<?php

use App\Companies\CompanyIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every project table a company: its rows are only visible to that company (row-level
 * security), project codes are unique per company, links between rows stay inside it, and every
 * person a row names must belong to the company.
 */
return new class extends Migration
{
    /** Parents first, so the tables they link to already allow company links. */
    private const array TABLES = [
        'projects', 'project_members', 'project_categories', 'project_milestones', 'packages',
        'package_milestones', 'sent_reminders',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            CompanyIsolation::addCompanyColumn($table);
        }

        DB::statement('DROP INDEX projects_code_unique');
        DB::statement('CREATE UNIQUE INDEX projects_code_unique ON projects (company_id, lower(code))');

        foreach (['projects', 'project_categories', 'project_milestones', 'packages'] as $table) {
            CompanyIsolation::allowLinksTo($table);
        }

        $this->keepLinksInsideCompanies();

        foreach (self::TABLES as $table) {
            CompanyIsolation::enable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            CompanyIsolation::removeCompanyColumn($table);
        }

        $this->restoreGlobalCodesAndLinks();
    }

    private function keepLinksInsideCompanies(): void
    {
        CompanyIsolation::linkWithinCompany('project_members', 'project_id', 'projects');
        CompanyIsolation::linkWithinCompany('project_categories', 'project_id', 'projects');
        CompanyIsolation::linkWithinCompany('project_milestones', 'project_id', 'projects');
        CompanyIsolation::linkWithinCompany('packages', 'project_id', 'projects', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('packages', 'parent_id', 'packages', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('packages', 'category_id', 'project_categories', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('package_milestones', 'package_id', 'packages');
        CompanyIsolation::linkWithinCompany('package_milestones', 'project_milestone_id', 'project_milestones');
        CompanyIsolation::linkWithinCompany('sent_reminders', 'package_id', 'packages', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('sent_reminders', 'project_milestone_id', 'project_milestones');

        CompanyIsolation::linkToMember('project_members', 'user_id');
        CompanyIsolation::linkToMember('packages', 'owner_id');
        CompanyIsolation::linkToMember('packages', 'buyer_id');
        CompanyIsolation::linkToMember('packages', 'risk_flagged_by_id');
        CompanyIsolation::linkToMember('sent_reminders', 'user_id');
    }

    private function restoreGlobalCodesAndLinks(): void
    {
        DB::statement('CREATE UNIQUE INDEX projects_code_unique ON projects (lower(code))');

        $links = [
            ['project_members', 'project_id', 'projects', 'CASCADE'], ['project_categories', 'project_id', 'projects', 'CASCADE'],
            ['project_milestones', 'project_id', 'projects', 'CASCADE'], ['packages', 'project_id', 'projects', 'RESTRICT'],
            ['packages', 'parent_id', 'packages', 'RESTRICT'], ['packages', 'category_id', 'project_categories', 'RESTRICT'],
            ['package_milestones', 'package_id', 'packages', 'CASCADE'], ['package_milestones', 'project_milestone_id', 'project_milestones', 'CASCADE'],
            ['sent_reminders', 'package_id', 'packages', 'RESTRICT'], ['sent_reminders', 'project_milestone_id', 'project_milestones', 'CASCADE'],
            ['project_members', 'user_id', 'users', 'RESTRICT'], ['packages', 'owner_id', 'users', 'RESTRICT'],
            ['packages', 'buyer_id', 'users', 'RESTRICT'], ['packages', 'risk_flagged_by_id', 'users', 'RESTRICT'],
            ['sent_reminders', 'user_id', 'users', 'RESTRICT'],
        ];

        foreach ($links as [$table, $column, $parent, $onDelete]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES {$parent} (id) ON DELETE {$onDelete}");
        }
    }
};
