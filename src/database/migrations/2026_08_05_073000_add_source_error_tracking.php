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
            $table->unsignedTinyInteger('consecutive_failures')->default(0)->after('enabled');
            $table->timestamp('last_success_at')->nullable()->after('last_backfilled_at');
            $table->timestamp('last_error_at')->nullable()->after('last_success_at');
            $table->text('last_error')->nullable()->after('last_error_at');
            $table->timestamp('disabled_at')->nullable()->after('last_error');
            $table->string('disabled_reason')->nullable()->after('disabled_at');
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->unsignedTinyInteger('consecutive_failures')->default(0)->after('enabled');
            $table->timestamp('last_success_at')->nullable()->after('last_backfilled_at');
            $table->timestamp('last_error_at')->nullable()->after('last_success_at');
            $table->text('last_error')->nullable()->after('last_error_at');
            $table->timestamp('disabled_at')->nullable()->after('last_error');
            $table->string('disabled_reason')->nullable()->after('disabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table) {
            $table->dropColumn([
                'consecutive_failures',
                'last_success_at',
                'last_error_at',
                'last_error',
                'disabled_at',
                'disabled_reason',
            ]);
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropColumn([
                'consecutive_failures',
                'last_success_at',
                'last_error_at',
                'last_error',
                'disabled_at',
                'disabled_reason',
            ]);
        });
    }
};
