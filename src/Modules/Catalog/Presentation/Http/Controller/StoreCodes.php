<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * A store named by its code in an address or a form, as every store screen names it. **An off store
 * too** (§1.3, P6): a Super Admin fills and sets up a store before it opens, and Platform's
 * `storeByCode` answers only stores that are on - read alone, an off store's code reached the
 * handler as no store at all and was refused (the review of step 3). Whether the reader may act
 * there is the handler's answer; a code that is no store stays as it came, refused as the job would be.
 */
final class StoreCodes
{
    public static function id(PlatformApi $platform, string $code): string
    {
        foreach ($platform->allStores() as $store) {
            if (strtolower($store->code) === strtolower(trim($code))) {
                return $store->id;
            }
        }

        return $code;
    }
}
