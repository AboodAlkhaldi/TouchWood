<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The Gateway page (payments.md §4.2): one store's gateway, keys, notice addresses, Apple Pay and timers. */
#[TypeScript]
final class GatewayPage extends Data
{
    /**
     * @param  list<GatewayStoreData>  $stores
     */
    public function __construct(
        public string $storeCode,
        public string $storeName,
        public string $storeTimezone,
        public array $stores,
        public bool $offered,
        public bool $setUp,
        public bool $active,
        public ?string $mode,
        public ?GatewayKeyData $liveKey,
        public ?GatewayKeyData $testKey,
        public string $noticeLive,
        public string $noticeTest,
        public string $currentDomain,
        public bool $applePayLive,
        public bool $applePayTest,
        public int $windowMinutes,
        public int $waitMinutes,
        public int $holdMinutes,
    ) {}
}
