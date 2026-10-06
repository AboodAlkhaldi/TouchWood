<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\OpenStaffView;

/**
 * Opens the shop of the store being worked in, as the staff member asking (spec §1.11). It names no
 * store: the panel's own answer for this person decides it, so a request cannot ask for another.
 */
final readonly class OpenStaffView {}
