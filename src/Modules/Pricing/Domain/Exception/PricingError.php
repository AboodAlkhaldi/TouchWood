<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Pricing module raises (pricing.md §7). Other modules see them only as
 * `DomainError`, by their `pricing.*` type: modules export no error classes (§2.1).
 */
abstract class PricingError extends DomainError {}
