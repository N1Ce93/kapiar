<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_monitor_controls', function (Blueprint $table): void {
            $table->uuid('recovery_id')->nullable();
            $table->string('recovery_status', 20)->nullable();
            $table->timestamp('recovery_requested_at')->nullable();
            $table->timestamp('recovery_started_at')->nullable();
            $table->timestamp('recovery_finished_at')->nullable();
            $table->text('recovery_error')->nullable();
            $table->json('recovery_stats')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gmail_monitor_controls', function (Blueprint $table): void {
            $table->dropColumn([
                'recovery_id', 'recovery_status', 'recovery_requested_at', 'recovery_started_at',
                'recovery_finished_at', 'recovery_error', 'recovery_stats',
            ]);
        });
    }
};
