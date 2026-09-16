<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\ValueObject;

use DateTimeZone;
use Modules\Platform\Domain\Exception\InvalidTimezone;

/**
 * An IANA timezone identifier. Times are stored in UTC and shown in the store's timezone.
 */
final readonly class Timezone
{
    private function __construct(
        public string $identifier,
    ) {}

    public static function fromString(string $identifier): self
    {
        if (! in_array($identifier, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidTimezone($identifier);
        }

        return new self($identifier);
    }
}
