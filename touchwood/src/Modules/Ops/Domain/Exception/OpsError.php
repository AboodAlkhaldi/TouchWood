<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Ops module raises (ops.md §7).
 */
abstract class OpsError extends DomainError {}
