<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendCustomerEmailVerification;

/**
 * "Send me the verification link again" (spec §1.2): for the customer signed in, so nobody can make
 * someone else's inbox ring.
 */
final readonly class ResendCustomerEmailVerification {}
