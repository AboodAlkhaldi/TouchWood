<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * Every Shipping refusal (shipping.md §7): "shipping.*", worded in both languages.
 */
abstract class ShippingError extends DomainError {}
