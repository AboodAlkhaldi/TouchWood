<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\External;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Sync\Application\Connections\NoticeAddresses;
use Shared\Domain\ValueObject\StoreId;

/**
 * The notice address on the app's own address (`notify-routes.php`): HTTPS, as the provider calls it.
 */
final readonly class UrlNoticeAddresses implements NoticeAddresses
{
    public function __construct(
        private UrlGenerator $urls,
    ) {}

    public function for(StoreId $store): string
    {
        return $this->urls->to('sync/notify/'.$store->value, [], true);
    }
}
