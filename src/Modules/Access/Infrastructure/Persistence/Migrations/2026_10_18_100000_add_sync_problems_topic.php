<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| A fifth notification topic, Sync problems (access.md amendment 71; sync.md §9.3 #18). Nobody's
| choice is written for it: a topic with no row reads as its default - in the panel on, email off.
|
| Undone, the choices made for it go with the topic.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.staff_notification_preferences DROP CONSTRAINT staff_notification_preferences_topic');
        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY', 'SYNC_PROBLEMS'))");
    }

    public function down(): void
    {
        DB::table('access.staff_notification_preferences')->where('topic', 'SYNC_PROBLEMS')->delete();
        DB::statement('ALTER TABLE access.staff_notification_preferences DROP CONSTRAINT staff_notification_preferences_topic');
        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY'))");
    }
};
