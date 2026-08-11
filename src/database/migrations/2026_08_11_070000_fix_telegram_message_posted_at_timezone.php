<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('telegram_messages')
            ->whereNotNull('posted_at')
            ->select(['id', 'posted_at'])
            ->orderBy('id')
            ->chunkById(500, function ($messages): void {
                foreach ($messages as $message) {
                    // Existing values contain the UTC wall clock stored as local time.
                    $postedAt = CarbonImmutable::parse($message->posted_at, 'UTC')
                        ->setTimezone(config('app.timezone'))
                        ->format('Y-m-d H:i:s');

                    DB::table('telegram_messages')
                        ->where('id', $message->id)
                        ->update(['posted_at' => $postedAt]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The original timestamps were invalid and cannot be restored safely.
    }
};
