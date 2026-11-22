<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\AnonymizeDueAccounts;

/**
 * The daily sweep (spec §1.10, amendment 43: the quiet hour of the shop's own morning): every account whose fourteen days
 * have passed is anonymized. It acts as the system, under a reserved permission.
 */
final readonly class AnonymizeDueAccounts {}
