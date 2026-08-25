<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table): void {
            $table->string('content_selector', 1000)->nullable()->after('listing_url');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_sites', function (Blueprint $table): void {
            $table->dropColumn('content_selector');
        });
    }
};
