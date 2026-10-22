<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('integration_outbox_events')) {
            Schema::table('integration_outbox_events', function (Blueprint $table) {
                if (!Schema::hasColumn('integration_outbox_events', 'fence_token')) {
                    $table->string('fence_token', 100)->nullable()->after('status')->comment('Fencing token for CAS atomic claim');
                }
                if (!Schema::hasColumn('integration_outbox_events', 'next_retry_at')) {
                    $table->timestamp('next_retry_at')->nullable()->after('last_attempt_at')->comment('Next scheduled retry timestamp');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('integration_outbox_events')) {
            Schema::table('integration_outbox_events', function (Blueprint $table) {
                if (Schema::hasColumn('integration_outbox_events', 'fence_token')) {
                    $table->dropColumn('fence_token');
                }
                if (Schema::hasColumn('integration_outbox_events', 'next_retry_at')) {
                    $table->dropColumn('next_retry_at');
                }
            });
        }
    }
};
