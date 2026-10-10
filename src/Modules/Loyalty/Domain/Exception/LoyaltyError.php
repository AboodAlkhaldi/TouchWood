<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Loyalty module raises (loyalty.md §7).
 */
abstract class LoyaltyError extends DomainError {}
