<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

/**
 * Someone who gave the right password in this browser and has not entered the code yet.
 * $needsPhone: a Super Admin whose phone was reset first enters a new number (amendment 14).
 */
final readonly class PendingSignIn
{
    public function __construct(
        public string $staffId,
        public bool $needsPhone,
    ) {}
}
