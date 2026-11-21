<?php

use App\Companies\CompanyIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project notifications name their project in a real column, not only in the data, so hiding the
 * notifications of projects a person has left can use an index. Other modules' notifications leave it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications ADD COLUMN project_id bigint');
        CompanyIsolation::linkWithinCompany('notifications', 'project_id', 'projects');
        DB::statement('CREATE INDEX notifications_project_id_index ON notifications (company_id, project_id) WHERE project_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP COLUMN project_id');
    }
};
