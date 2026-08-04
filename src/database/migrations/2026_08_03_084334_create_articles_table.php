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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 768)->unique();
            $table->text('title')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->boolean('is_backfilled')->default(false);
            $table->timestamps();

            $table->index(['monitored_site_id', 'published_at']);
            $table->index(['is_backfilled', 'notified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
