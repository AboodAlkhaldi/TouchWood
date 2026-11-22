<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Shared\Domain\Text\PhoneNumbers;

/**
 * A phone number in E.164 (+9665…), any country (owner's decision, 2026-09-18), by the rule every
 * module shares (`PhoneNumbers`, stage 7 step 3): spaces, dashes, dots and brackets dropped, a
 * leading 00 made +, Arabic-keyboard digits the same digits (amendment 63).
 */
final readonly class PhoneNumber
{
    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        return new self(PhoneNumbers::normalize($value)
            ?? throw new InvalidAccessAttribute('phone', 'expected an international number such as +966501234567'));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The number as a screen may show it: the last three digits, everything before them masked
     * (stage 2b, P4). The code page has to say which number a code went to — a person may have two
     * — without putting the number into a page anyone holding the browser can read.
     *
     * Three digits is the owner's decision of 2026-09-19. Every number here has at least seven, so
     * there is always something to mask: of() refuses anything shorter.
     */
    public function masked(): string
    {
        $digits = substr($this->value, 1);
        $shown = mb_substr($digits, -3);

        return str_repeat("\u{2022}", mb_strlen($digits) - 3).$shown;
    }
}
