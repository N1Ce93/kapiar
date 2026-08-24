<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_subject_keywords')) {
            Schema::create('email_subject_keywords', function (Blueprint $table) {
                $table->id();
                $table->string('phrase')->unique();
                $table->string('label_name');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gmail_monitor_states')) {
            Schema::create('gmail_monitor_states', function (Blueprint $table) {
                $table->id();
                $table->string('email_address')->unique();
                $table->string('history_id', 64);
                $table->timestamp('initialized_at');
                $table->timestamp('last_checked_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gmail_processing_messages')) {
            Schema::create('gmail_processing_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gmail_monitor_state_id')->constrained()->cascadeOnDelete();
                $table->string('gmail_message_id', 128);
                $table->json('matched_keywords');
                $table->json('target_labels');
                $table->timestamp('telegram_sent_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(
                    ['gmail_monitor_state_id', 'gmail_message_id'],
                    'gmail_processing_state_message_unique',
                );
                $table->index(['gmail_monitor_state_id', 'telegram_sent_at'], 'gmail_processing_pending_index');
            });

            return;
        }

        if (! Schema::hasIndex('gmail_processing_messages', 'gmail_processing_state_message_unique')) {
            Schema::table('gmail_processing_messages', function (Blueprint $table) {
                $table->unique(
                    ['gmail_monitor_state_id', 'gmail_message_id'],
                    'gmail_processing_state_message_unique',
                );
            });
        }

        if (! Schema::hasIndex('gmail_processing_messages', 'gmail_processing_pending_index')) {
            Schema::table('gmail_processing_messages', function (Blueprint $table) {
                $table->index(['gmail_monitor_state_id', 'telegram_sent_at'], 'gmail_processing_pending_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_processing_messages');
        Schema::dropIfExists('gmail_monitor_states');
        Schema::dropIfExists('email_subject_keywords');
    }
};
