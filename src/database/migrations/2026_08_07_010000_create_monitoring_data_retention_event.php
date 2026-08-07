<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EVENT_NAME = 'prune_monitoring_old_data_monthly';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP EVENT IF EXISTS '.self::EVENT_NAME);

        DB::unprepared(<<<'SQL'
CREATE EVENT prune_monitoring_old_data_monthly
ON SCHEDULE EVERY 1 MONTH
STARTS TIMESTAMP(DATE_ADD(LAST_DAY(CURRENT_DATE), INTERVAL 1 DAY), '01:00:00')
ON COMPLETION PRESERVE
ENABLE
DO
BEGIN
    DELETE FROM articles
    WHERE COALESCE(published_at, discovered_at, created_at) <
        STR_TO_DATE(DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01'), '%Y-%m-%d');

    DELETE FROM telegram_messages
    WHERE COALESCE(posted_at, created_at) <
        STR_TO_DATE(DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01'), '%Y-%m-%d');
END
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP EVENT IF EXISTS '.self::EVENT_NAME);
    }
};
