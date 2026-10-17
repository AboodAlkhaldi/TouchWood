<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Sync module raises (sync.md §7). Other modules see them only as
 * `DomainError`, by their `sync.*` type: modules export no error classes.
 */
abstract class SyncError extends DomainError {}
