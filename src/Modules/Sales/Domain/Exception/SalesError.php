<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Sales module raises (sales.md §7).
 */
abstract class SalesError extends DomainError {}
