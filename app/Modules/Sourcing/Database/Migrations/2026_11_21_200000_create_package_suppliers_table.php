<?php

use App\Companies\CompanyIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The suppliers asked for a quote on a project package, and the one chosen. Packages and suppliers are
 * never deleted (cancelled and archived instead), so their links are kept with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_suppliers', function (Blueprint $table) {
            $table->id();
            // Linked inside the company below.
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('status', 20);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            // A supplier is listed once per package; also finds a package's suppliers.
            $table->unique(['package_id', 'supplier_id']);
            // A supplier's packages, for its page.
            $table->index('supplier_id');
        });

        DB::statement("ALTER TABLE package_suppliers ADD CONSTRAINT package_suppliers_status_check CHECK (status IN ('asked', 'offer_received', 'declined', 'chosen'))");
        // At most one chosen supplier per package. Also what the risk check looks up for every package.
        DB::statement("CREATE UNIQUE INDEX package_suppliers_one_chosen_unique ON package_suppliers (package_id) WHERE status = 'chosen'");

        CompanyIsolation::addCompanyColumn('package_suppliers');
        CompanyIsolation::linkWithinCompany('package_suppliers', 'package_id', 'packages', 'RESTRICT');
        CompanyIsolation::linkWithinCompany('package_suppliers', 'supplier_id', 'suppliers', 'RESTRICT');
        CompanyIsolation::enable('package_suppliers');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_suppliers');
    }
};
