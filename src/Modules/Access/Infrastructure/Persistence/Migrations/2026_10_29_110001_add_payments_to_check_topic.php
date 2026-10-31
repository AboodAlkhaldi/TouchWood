<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| An eighth notification topic, Payments to check (access.md amendment 84; ops.md §1.6 S6, S7): a
| bank transfer still unchecked 72 hours after its receipt was uploaded, or a payment not accepted -
| a refund owed. Nobody's choice is written for it: a topic with no row reads as its default - in
| the panel on, email off.
|
| Undone, the choices made for it go with the topic.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.staff_notification_preferences DROP CONSTRAINT staff_notification_preferences_topic');
        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY', 'SYNC_PROBLEMS', 'PAYMENT_PROBLEMS', 'RETURNS', 'PAYMENTS_TO_CHECK'))");
    }

    public function down(): void
    {
        DB::table('access.staff_notification_preferences')->where('topic', 'PAYMENTS_TO_CHECK')->delete();
        DB::statement('ALTER TABLE access.staff_notification_preferences DROP CONSTRAINT staff_notification_preferences_topic');
        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY', 'SYNC_PROBLEMS', 'PAYMENT_PROBLEMS', 'RETURNS'))");
    }
};
