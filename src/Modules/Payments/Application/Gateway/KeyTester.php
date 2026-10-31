<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Exception\KeyTestFailed;

/**
 * Tests a pasted key before it is saved (payments.md §1.2), for each permission this design uses - a
 * key's permissions are per endpoint (notes §0 #18): the methods list, opening a session (it charges
 * nothing), and asking for a payment that does not exist (a "not found", never a 401).
 */
interface KeyTester
{
    /**
     * @throws KeyPermissionMissing|KeyTestFailed|GatewayUnreachable
     */
    public function test(string $apiBase, #[\SensitiveParameter] string $key, string $currencyCode): void;
}
