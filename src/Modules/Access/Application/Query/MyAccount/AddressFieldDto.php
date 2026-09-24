<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

/**
 * One field of a store's address format, as the form that asks for it needs to know it (spec §1.9).
 *
 * Both labels travel, and the page picks: which language a customer reads the shop in is in the
 * address of the page, and this read side has no business knowing it.
 */
final readonly class AddressFieldDto
{
    public function __construct(
        public string $key,
        public string $labelAr,
        public string $labelEn,
        public bool $required,
        public int $maxLength,
    ) {}
}
