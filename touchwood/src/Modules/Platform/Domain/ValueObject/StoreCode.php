<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\InvalidStoreAttribute;

/**
 * The store's URL segment: brand.com/{code}.
 *
 * Only the format lives here. Which top-level paths the application keeps for itself is a list the
 * modules build at boot (ReservedPaths), so creating a store checks it separately.
 */
final readonly class StoreCode
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z]{2,8}\z/', $value) !== 1) {
            throw new InvalidStoreAttribute('code', 'expected 2 to 8 lowercase letters');
        }

        return new self($value);
    }
}
