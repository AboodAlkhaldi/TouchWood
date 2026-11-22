<?php

declare(strict_types=1);

namespace Modules\Access\Application\Authorization;

use LogicException;

/**
 * A handler checked a permission wrongly: one no module declares, or a store-free permission
 * against a store (or a per-store one against no store). A programming error, never a DomainError:
 * it fails loudly in tests instead of quietly refusing a person.
 */
final class InvalidPermissionCheck extends LogicException {}
