<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SetOwnCriticalSms;

/**
 * A Super Admin's own critical-SMS switch (amendment 81): whether Ops' critical SMS reaches them.
 */
final readonly class SetOwnCriticalSms
{
    public function __construct(
        public bool $on,
    ) {}
}
