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
        Schema::create('telegram_channels', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('username')->unique();
            $table->string('url')->unique();
            $table->string('telegram_peer')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_backfilled_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'username']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_channels');
    }
};
