<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A field out of range, named by its field and rule (§7).
 */
final class InvalidPaymentsInput extends PaymentsError
{
    public function __construct(public readonly string $field, public readonly string $rule)
    {
        parent::__construct("Invalid {$this->field}: {$this->rule}");
    }

    public function type(): string
    {
        return 'payments.invalid_payments_input';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['field' => $this->field, 'rule' => $this->rule];
    }
}
