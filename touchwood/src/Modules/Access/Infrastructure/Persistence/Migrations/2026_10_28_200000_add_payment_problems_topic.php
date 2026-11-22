<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| A sixth notification topic, Payment problems (access.md amendment 78; payments.md §1.2): the
| gateway's key expires in 30, 7 or 1 days, or MyFatoorah refused it. Nobody's choice is written for
| it: a topic with no row reads as its default - in the panel on, email off.
|
| Undone, the choices made for it go with the topic.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.staff_notification_preferences DROP CONSTRAINT staff_notification_preferences_topic');
        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY', 'SYNC_PROBLEMS', 'PAYMENT_PROBLEMS'))");
    }

    public function down(): void
    {
        DB::table('access.staff_notification_preferences')->where('topic', 'PAYMENT_PROBLEMS')->delete();
        DB::statement('ALTER TABLE access.staff_notification_preferences DROP CONSTRAINT staff_notification_preferences_topic');
        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY', 'SYNC_PROBLEMS'))");
    }
};
