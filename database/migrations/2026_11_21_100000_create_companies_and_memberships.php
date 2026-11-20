<?php

use App\Companies\CompanyIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 40)->unique();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        // Every address a company has ever had, its current one included. The primary key means an
        // address, once used, never goes to another company, so old links can't lead somewhere else.
        Schema::create('company_slugs', function (Blueprint $table) {
            $table->string('slug', 40)->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index('company_id');
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            // An invitation is a membership that hasn't been accepted yet (joined_at is null).
            $table->string('invited_name')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitation_token', 64)->nullable()->unique();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('admin', 'buyer', 'viewer'))");

        // Roles now belong to memberships: a person can be an admin in one company and a viewer in another.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->boolean('is_platform_admin')->default(false);
            $table->foreignId('last_company_id')->nullable()->constrained('companies')->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropPrimary();
        });
        CompanyIsolation::addCompanyColumn('settings');
        Schema::table('settings', function (Blueprint $table) {
            $table->primary(['company_id', 'key']);
        });
        CompanyIsolation::enable('settings');

        // API clients belong to one company. The token endpoint looks clients up before any company
        // is known, so this table has no row-level security; the API sets the client's company instead.
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        CompanyIsolation::disable('settings');
        Schema::table('settings', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropConstrainedForeignId('company_id');
            $table->primary('key');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_company_id');
            $table->dropColumn('is_platform_admin');
            $table->string('role', 20)->default('viewer');
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'buyer', 'viewer'))");

        Schema::dropIfExists('memberships');
        Schema::dropIfExists('company_slugs');
        Schema::dropIfExists('companies');
    }
};
