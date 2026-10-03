<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Catalog module raises (catalog.md §7).
 */
abstract class CatalogError extends DomainError {}
