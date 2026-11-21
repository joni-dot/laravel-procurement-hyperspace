<?php

use App\Companies\CompanyIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications of every module, in Laravel's own table layout plus the company. "type" holds the
 * kind (such as "package_assigned"), not a class name; App\Notifications\NotificationKinds knows which
 * module each kind belongs to. A module adds the columns its kinds need (projects add project_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 50);
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // A person's notifications, newest first: the notifications page and the daily summary.
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });

        // The unread count every page shows. Partial, so it stays small however many old notifications there are.
        DB::statement('CREATE INDEX notifications_unread_index ON notifications (notifiable_type, notifiable_id, type) WHERE read_at IS NULL');

        CompanyIsolation::addCompanyColumn('notifications');
        // Only people of the company are told about its work.
        CompanyIsolation::linkToMember('notifications', 'notifiable_id');
        CompanyIsolation::enable('notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
