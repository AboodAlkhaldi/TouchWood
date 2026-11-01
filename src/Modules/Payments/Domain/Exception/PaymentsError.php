<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * Every Payments refusal (payments.md §7): "payments.*", worded in both languages.
 */
abstract class PaymentsError extends DomainError {}
