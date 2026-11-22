<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\ChangeKeys;

/**
 * A Super Admin pastes a mode's key and notice secret, with the key's expiry date as chosen in
 * MyFatoorah's portal (payments.md §1.2). The store's first key sets its gateway up, switched off
 * (`SetUpGateway`, §3).
 */
final readonly class ChangeKeys
{
    public function __construct(
        public string $storeId,
        public string $mode,
        #[\SensitiveParameter] public string $key,
        #[\SensitiveParameter] public string $secret,
        public string $expiresOn,
    ) {}
}
