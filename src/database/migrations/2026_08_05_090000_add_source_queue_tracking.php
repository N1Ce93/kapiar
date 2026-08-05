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
        Schema::table('monitored_sites', function (Blueprint $table) {
            $table->timestamp('last_queued_at')->nullable()->after('last_backfilled_at');
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->timestamp('last_queued_at')->nullable()->after('last_backfilled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table) {
            $table->dropColumn('last_queued_at');
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropColumn('last_queued_at');
        });
    }
};
