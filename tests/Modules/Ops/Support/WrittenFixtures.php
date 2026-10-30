<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/** Customers as step 7's tests need them, set straight in Access's table: Access's own commands are tested in Access. */
final class WrittenFixtures
{
    /**
     * A customer of that store who may receive offers by email (ops.md §1.3): agreed, email verified,
     * active, no deletion pending.
     */
    public static function agreeing(string $email, string $storeCode = 'sa', string $locale = 'en', string $accountType = 'individual'): string
    {
        $id = Fx::customer($email, $storeCode, $accountType);
        self::set($id, [
            'email_verified_at' => CarbonImmutable::now()->toIso8601String(),
            'marketing_consent' => true,
            'marketing_consent_source' => 'ACCOUNT',
            'marketing_consent_changed_at' => CarbonImmutable::now()->toIso8601String(),
            'locale' => $locale,
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $values */
    public static function set(string $customerId, array $values): void
    {
        DB::table('access.customers')->where('id', $customerId)->update($values);
        // Access caches its customers: what the next read sees must be what was just set.
        cache()->flush();
    }
}
