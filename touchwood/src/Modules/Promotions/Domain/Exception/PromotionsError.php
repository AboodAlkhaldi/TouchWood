<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Promotions module raises (promotions.md §7).
 */
abstract class PromotionsError extends DomainError {}
