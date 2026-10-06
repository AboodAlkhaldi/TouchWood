<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\LeaveStaffView;

/**
 * Ends the staff view this browser carries (spec §1.11): the shop shows whoever the shop's own
 * session holds again - a visitor, or a customer still signed in underneath.
 */
final readonly class LeaveStaffView {}
