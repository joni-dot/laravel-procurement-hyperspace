<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invitation tokens now live on the membership they lead to.
        Schema::dropIfExists('invitation_tokens');

        // The address the company typed, so its list never shows an address the person changed to
        // later: that one belongs to their account, which the company doesn't see until they join.
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('invited_email')->nullable()->after('invited_name');
        });

        DB::statement('UPDATE memberships SET invited_email = users.email FROM users WHERE users.id = memberships.user_id AND memberships.joined_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('invited_email');
        });

        Schema::create('invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
