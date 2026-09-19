<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations;

/**
 * The scheduled sweep (amendment 30): every Super Admin invitation left unaccepted past its hours
 * is cancelled, and its email and phone freed.
 */
final readonly class CancelExpiredSuperAdminInvitations {}
