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
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('message_id');
            $table->text('text')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->boolean('is_backfilled')->default(false);
            $table->timestamps();

            $table->unique(['telegram_channel_id', 'message_id']);
            $table->index(['telegram_channel_id', 'posted_at']);
            $table->index(['is_backfilled', 'notified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
