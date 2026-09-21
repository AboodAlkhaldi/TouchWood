<?php

declare(strict_types=1);

namespace Modules\Access\Application\Permission;

use LogicException;

/**
 * A module declared a permission wrongly. A programming error, raised at boot — never shown to a
 * customer and never a DomainError.
 */
final class InvalidPermissionDefinition extends LogicException {}
