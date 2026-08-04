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
        Schema::create('telegram_message_keyword_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->string('matched_text');
            $table->text('context')->nullable();
            $table->timestamps();

            $table->unique(['telegram_message_id', 'keyword_id'], 'tg_msg_keyword_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_message_keyword_hits');
    }
};
