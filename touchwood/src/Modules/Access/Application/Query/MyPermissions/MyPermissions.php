<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyPermissions;

/**
 * What the current person may do, and where: the admin menu is built from it (handoff §7.5, spec
 * §3.2 amendment 8). It shows only the reader's own permissions, so it needs none.
 */
final readonly class MyPermissions {}
