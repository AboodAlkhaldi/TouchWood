<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A key whose test call failed when it was pasted (§1.2): nothing is saved; the reason is named.
 */
final class KeyTestFailed extends PaymentsError
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The key's test failed: {$this->reason}");
    }

    public function type(): string
    {
        return 'payments.key_test_failed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
