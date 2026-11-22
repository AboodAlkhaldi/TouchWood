<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Access module raises (Access spec §7).
 */
abstract class AccessError extends DomainError {}
