<?php

namespace Tests\Feature\Companies;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The database itself keeps companies apart, behind the app's own checks.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Tables with a company_id that the database can't lock, and why. */
    private const array UNLOCKED_COMPANY_TABLES = [
        // The token endpoint looks clients up before any company is known.
        'oauth_clients',
        // Memberships connect people and companies; the app reads them before opening a company.
        'memberships',
        'company_slugs',
    ];

    public function test_every_table_with_company_rows_is_locked_to_the_sessions_company()
    {
        $tables = DB::table('information_schema.columns as c')
            ->join('pg_class as t', 't.relname', '=', 'c.table_name')
            ->where('c.table_schema', 'public')
            ->where('c.column_name', 'company_id')
            ->whereNotIn('c.table_name', self::UNLOCKED_COMPANY_TABLES)
            ->orderBy('c.table_name')
            ->get(['c.table_name', 't.relrowsecurity', 't.relforcerowsecurity']);

        $this->assertGreaterThanOrEqual(17, $tables->count());

        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity, "{$table->table_name} has no row-level security.");
            $this->assertTrue($table->relforcerowsecurity, "{$table->table_name} doesn't force row-level security on its owner.");
            $this->assertTrue(
                DB::table('pg_policies')->where('tablename', $table->table_name)->where('policyname', 'company_isolation')->exists(),
                "{$table->table_name} has no company policy.",
            );
        }
    }

    public function test_the_app_connects_as_a_user_that_cannot_get_around_the_lock()
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

        $this->assertFalse($role->rolsuper, 'The app must not connect as a superuser.');
        $this->assertFalse($role->rolbypassrls, 'The app must not connect as a user that bypasses row-level security.');
        $this->assertSame(0, DB::table('pg_tables')->where('schemaname', 'public')->whereRaw('tableowner = current_user')->count(), 'The app must not own tables; owners can switch the lock off.');
    }

    public function test_the_database_hides_and_refuses_other_companies_rows_even_without_the_apps_filter()
    {
        $ours = Category::factory()->create(['name' => 'Ours']);
        $otherCompany = Company::factory()->create();
        $this->enterCompany($otherCompany);
        Category::factory()->create(['name' => 'Theirs']);

        $this->assertSame(['Theirs'], DB::table('categories')->pluck('name')->all());
        $this->assertSame(0, DB::table('categories')->where('id', $ours->id)->update(['name' => 'Changed']));

        $this->expectException(QueryException::class);
        DB::table('categories')->insert(['company_id' => $ours->company_id, 'name' => 'Planted', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_nothing_is_visible_outside_a_company()
    {
        Supplier::factory()->create();
        app(CurrentCompany::class)->clear();

        $this->assertSame(0, Supplier::query()->count());
        $this->assertSame(0, DB::table('suppliers')->count());
    }

    public function test_rows_cannot_link_to_another_companys_rows()
    {
        $theirSupplier = Supplier::factory()->create();
        $this->enterCompany(Company::factory()->create());
        $ourCategory = Category::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('category_supplier')->insert(['supplier_id' => $theirSupplier->id, 'category_id' => $ourCategory->id]);
    }

    public function test_creating_company_data_outside_a_company_is_refused()
    {
        app(CurrentCompany::class)->clear();

        $this->expectException(\LogicException::class);
        Category::query()->create(['name' => 'Nowhere']);
    }
}
