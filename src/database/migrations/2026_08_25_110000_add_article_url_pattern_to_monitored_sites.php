<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table): void {
            $table->string('article_url_pattern', 1000)->nullable()->after('content_selector');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table): void {
            $table->dropColumn('article_url_pattern');
        });
    }
};
