<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the B2B module raises (b2b.md §7).
 */
abstract class B2BError extends DomainError {}
