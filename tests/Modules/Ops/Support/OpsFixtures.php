<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/** Inbox entries for Ops' tests, written through the module's own repository. */
final class OpsFixtures
{
    public static function customerEntry(string $customerId, string $storeCode = 'sa', ?CarbonImmutable $at = null, MessageKind $kind = MessageKind::OrderApproved, string $title = 'Order SA-0001 approved'): string
    {
        $id = strtolower((string) Str::ulid());
        app(Inbox::class)->add(InboxEntry::forCustomer(
            $id,
            $customerId,
            StoreId::fromString(Fx::storeId($storeCode)),
            $kind,
            'اعتُمد الطلب SA-0001',
            $title,
            'نجهّز طلبك الآن.',
            'We are preparing your order.',
            '/'.$storeCode.'/en/account/orders/SA-0001',
            ($at ?? CarbonImmutable::now())->toDateTimeImmutable(),
        ));

        return $id;
    }

    public static function staffEntry(string $staffId, string $storeCode = 'sa', ?CarbonImmutable $at = null, MessageKind $kind = MessageKind::NewOrder): string
    {
        $id = strtolower((string) Str::ulid());
        app(Inbox::class)->add(InboxEntry::forStaff(
            $id,
            $staffId,
            StoreId::fromString(Fx::storeId($storeCode)),
            $kind,
            'طلب جديد SA-0001',
            'New order SA-0001',
            'وصل طلب جديد.',
            'A new order was placed.',
            '/admin/orders',
            ($at ?? CarbonImmutable::now())->toDateTimeImmutable(),
        ));

        return $id;
    }
}
