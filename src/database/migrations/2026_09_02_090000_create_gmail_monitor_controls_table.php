<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_monitor_controls', function (Blueprint $table) {
            $table->id();
            $table->uuid('incident_id')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('alert_attempted_at')->nullable();
            $table->timestamp('alert_delivered_at')->nullable();
            $table->timestamps();
        });

        DB::table('gmail_monitor_controls')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_monitor_controls');
    }
};
