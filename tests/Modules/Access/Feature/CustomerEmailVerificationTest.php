<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Access\Public\Events\CustomerEmailVerified;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function verificationLink(): string
{
    return RecordingSecurityMessages::installed()->emailVerifications[0]['link'] ?? '';
}

function verifiedAt(string $customerId): ?string
{
    $at = DB::table('access.customers')->where('id', $customerId)->value('email_verified_at');

    return $at === null ? null : (string) $at;
}

describe('the email verification link (spec §1.2, amendment 38)', function () {
    it('verifies the address for whoever opens it, signed in or not, and sends them to the store', function () {
        Event::fake([CustomerEmailVerified::class]);
        $customerId = Fx::customer();

        get(verificationLink())->assertRedirect('/sa/en');

        expect(verifiedAt($customerId))->not->toBeNull()
            ->and(Fx::audits('access.customer.email_verified', $customerId))->toBe(1);

        Event::assertDispatched(CustomerEmailVerified::class, fn (CustomerEmailVerified $event): bool => $event->customerId === $customerId);
    });

    it('changes nothing when the same link is opened again', function () {
        $customerId = Fx::customer();
        get(verificationLink());
        $first = verifiedAt($customerId);

        get(verificationLink())->assertRedirect('/sa/en');

        expect(verifiedAt($customerId))->toBe($first)
            ->and(Fx::audits('access.customer.email_verified', $customerId))->toBe(1);
    });

    it('refuses a link that was changed on the way', function () {
        $customerId = Fx::customer();
        $other = Fx::customer('other@example.test');
        $tampered = str_replace($customerId, $other, verificationLink());

        get($tampered)->assertForbidden();

        expect(verifiedAt($other))->toBeNull();
    });

    it('stops working after 24 hours', function () {
        $customerId = Fx::customer();
        $link = verificationLink();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(25));

        get($link)->assertForbidden();

        expect(verifiedAt($customerId))->toBeNull();
    });

    it('still works at 23 hours', function () {
        $customerId = Fx::customer();
        $link = verificationLink();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(23));

        get($link)->assertRedirect('/sa/en');

        expect(verifiedAt($customerId))->not->toBeNull();
    });
});
