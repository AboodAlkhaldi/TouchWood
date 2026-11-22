<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * A role's name in Arabic and English, both required (handoff §5.2: no fallback on names).
 */
final readonly class RoleName
{
    private function __construct(
        public string $ar,
        public string $en,
    ) {}

    public static function of(string $ar, string $en): self
    {
        $ar = trim($ar);
        $en = trim($en);

        if ($ar === '' || $en === '') {
            throw new InvalidAccessAttribute('name', 'a role needs a name in Arabic and in English');
        }

        return new self($ar, $en);
    }

    public function equals(self $other): bool
    {
        return $this->ar === $other->ar && $this->en === $other->en;
    }
}
