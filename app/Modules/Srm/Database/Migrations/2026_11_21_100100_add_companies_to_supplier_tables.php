<?php

use App\Companies\CompanyIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every supplier table a company: its rows are only visible to that company (row-level
 * security), names and numbers are unique per company, and links between rows stay inside it.
 */
return new class extends Migration
{
    /** Parents first, so the tables they link to already allow company links. */
    private const array TABLES = [
        'categories', 'certificate_types', 'contract_types', 'rating_criteria', 'suppliers',
        'category_supplier', 'contacts', 'certificates', 'certificate_reminders', 'contracts',
        'contract_reminders', 'documents', 'supplier_ratings', 'rating_scores', 'supplier_spends', 'comments',
    ];

    public function up(): void
    {
        // The starting lists were shared by everyone. Now each company gets its own copy when it
        // is created (AddStartingLists), so the shared rows go.
        DB::table('contract_types')->delete();
        DB::table('rating_criteria')->delete();

        foreach (self::TABLES as $table) {
            CompanyIsolation::addCompanyColumn($table);
        }

        $this->makeNamesUniquePerCompany();

        foreach (['categories', 'certificate_types', 'contract_types', 'rating_criteria', 'suppliers', 'certificates', 'contracts', 'supplier_ratings'] as $table) {
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

        $this->restoreGlobalNamesAndLinks();
    }

    private function makeNamesUniquePerCompany(): void
    {
        foreach (['categories', 'certificate_types', 'contract_types', 'rating_criteria'] as $table) {
            DB::statement("DROP INDEX {$table}_name_unique");
            DB::statement("CREATE UNIQUE INDEX {$table}_name_unique ON {$table} (company_id, lower(name))");
        }

        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_supplier_number_unique');
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_supplier_number_unique UNIQUE (company_id, supplier_number)');
        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_country_code_business_id_unique');
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_country_code_business_id_unique UNIQUE (company_id, country_code, business_id)');
        // The supplier list is ordered by name within one company.
        DB::statement('DROP INDEX suppliers_name_index');
        DB::statement('CREATE INDEX suppliers_name_index ON suppliers (company_id, name)');

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_contract_number_unique');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_contract_number_unique UNIQUE (company_id, contract_number)');
    }

    private function keepLinksInsideCompanies(): void
    {
        foreach (['category_supplier', 'contacts', 'certificates', 'contracts', 'documents', 'supplier_ratings', 'supplier_spends', 'comments'] as $table) {
            CompanyIsolation::linkWithinCompany($table, 'supplier_id', 'suppliers');
        }

        CompanyIsolation::linkWithinCompany('category_supplier', 'category_id', 'categories');
        CompanyIsolation::linkWithinCompany('certificates', 'certificate_type_id', 'certificate_types', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('certificate_reminders', 'certificate_id', 'certificates');
        CompanyIsolation::linkWithinCompany('contracts', 'contract_type_id', 'contract_types', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('contract_reminders', 'contract_id', 'contracts');
        CompanyIsolation::linkWithinCompany('documents', 'contract_id', 'contracts');
        CompanyIsolation::linkWithinCompany('comments', 'contract_id', 'contracts');
        CompanyIsolation::linkWithinCompany('rating_scores', 'supplier_rating_id', 'supplier_ratings');
        CompanyIsolation::linkWithinCompany('rating_scores', 'rating_criterion_id', 'rating_criteria', 'RESTRICT');

        CompanyIsolation::linkToMember('suppliers', 'responsible_user_id');
        CompanyIsolation::linkToMember('suppliers', 'risk_overridden_by_user_id');
        CompanyIsolation::linkToMember('contracts', 'owner_user_id');
        CompanyIsolation::linkToMember('documents', 'uploaded_by_user_id');
        CompanyIsolation::linkToMember('supplier_ratings', 'rated_by_user_id');
        CompanyIsolation::linkToMember('comments', 'user_id');
    }

    private function restoreGlobalNamesAndLinks(): void
    {
        foreach (['categories', 'certificate_types', 'contract_types', 'rating_criteria'] as $table) {
            DB::statement("CREATE UNIQUE INDEX {$table}_name_unique ON {$table} (lower(name))");
        }

        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_supplier_number_unique UNIQUE (supplier_number)');
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_country_code_business_id_unique UNIQUE (country_code, business_id)');
        DB::statement('CREATE INDEX suppliers_name_index ON suppliers (name)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_contract_number_unique UNIQUE (contract_number)');

        $links = [
            ['category_supplier', 'supplier_id', 'suppliers', 'CASCADE'], ['category_supplier', 'category_id', 'categories', 'CASCADE'],
            ['contacts', 'supplier_id', 'suppliers', 'CASCADE'], ['certificates', 'supplier_id', 'suppliers', 'CASCADE'],
            ['certificates', 'certificate_type_id', 'certificate_types', 'RESTRICT'], ['certificate_reminders', 'certificate_id', 'certificates', 'CASCADE'],
            ['contracts', 'supplier_id', 'suppliers', 'CASCADE'], ['contracts', 'contract_type_id', 'contract_types', 'RESTRICT'],
            ['contract_reminders', 'contract_id', 'contracts', 'CASCADE'], ['documents', 'supplier_id', 'suppliers', 'CASCADE'],
            ['documents', 'contract_id', 'contracts', 'CASCADE'], ['supplier_ratings', 'supplier_id', 'suppliers', 'CASCADE'],
            ['rating_scores', 'supplier_rating_id', 'supplier_ratings', 'CASCADE'], ['rating_scores', 'rating_criterion_id', 'rating_criteria', 'RESTRICT'],
            ['supplier_spends', 'supplier_id', 'suppliers', 'CASCADE'], ['comments', 'supplier_id', 'suppliers', 'CASCADE'],
            ['comments', 'contract_id', 'contracts', 'CASCADE'],
            ['suppliers', 'responsible_user_id', 'users', 'SET NULL'], ['suppliers', 'risk_overridden_by_user_id', 'users', 'SET NULL'],
            ['contracts', 'owner_user_id', 'users', 'SET NULL'], ['documents', 'uploaded_by_user_id', 'users', 'SET NULL'],
            ['supplier_ratings', 'rated_by_user_id', 'users', 'SET NULL'], ['comments', 'user_id', 'users', 'RESTRICT'],
        ];

        foreach ($links as [$table, $column, $parent, $onDelete]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES {$parent} (id) ON DELETE {$onDelete}");
        }
    }
};
