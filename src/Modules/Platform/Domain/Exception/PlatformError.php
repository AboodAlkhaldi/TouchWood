<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Platform module raises (Platform spec §7.3).
 */
abstract class PlatformError extends DomainError {}
