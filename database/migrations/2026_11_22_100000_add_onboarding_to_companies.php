<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Filled with made-up data for demos and trials; marked "Demo" wherever the company is named.
            $table->boolean('is_demo')->default(false);
            // When an admin hid the getting-started checklist; it stays hidden for the whole company.
            $table->timestamp('onboarding_hidden_at')->nullable();
            // When an admin's Home first found every step done. The checklist then stays away for good, even
            // if a step later looks open again (the only colleague leaves), and costs no queries.
            $table->timestamp('onboarding_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['is_demo', 'onboarding_hidden_at', 'onboarding_completed_at']);
        });
    }
};
