<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table) {
            $table->timestamp('next_check_at')->nullable()->after('last_queued_at');
            $table->timestamp('check_pending_at')->nullable()->after('next_check_at');
            $table->string('check_claim_token', 36)->nullable()->after('check_pending_at');
            $table->string('last_error_type', 16)->nullable()->after('last_error');
            $table->timestamp('paused_at')->nullable()->after('last_error_type');
            $table->index(['enabled', 'next_check_at']);
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->timestamp('next_check_at')->nullable()->after('last_queued_at');
            $table->timestamp('check_pending_at')->nullable()->after('next_check_at');
            $table->string('check_claim_token', 36)->nullable()->after('check_pending_at');
            $table->string('last_error_type', 16)->nullable()->after('last_error');
            $table->timestamp('paused_at')->nullable()->after('last_error_type');
            $table->index(['enabled', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table) {
            $table->dropIndex(['enabled', 'next_check_at']);
            $table->dropColumn(['next_check_at', 'check_pending_at', 'check_claim_token', 'last_error_type', 'paused_at']);
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropIndex(['enabled', 'next_check_at']);
            $table->dropColumn(['next_check_at', 'check_pending_at', 'check_claim_token', 'last_error_type', 'paused_at']);
        });
    }
};
