<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * Something a person wrote for another to read: the reason staff give for rejecting, suspending or
 * reinstating, the note they may add to an approval, the customer's note with a new application.
 *
 * At most 1000 characters, line breaks kept (owner, 2026-09-27): room to list what is wrong in a
 * few sentences. It is shown on the company page and sent in an email, so it is text and nothing
 * else — the page escapes it like every other value.
 */
final readonly class Remark
{
    public const int MAX = 1000;

    private function __construct(public string $value) {}

    /**
     * @param  string  $attribute  which remark it is, for the error: "reason", "note"
     *
     * @throws InvalidCompanyAttribute
     */
    public static function of(string $attribute, string $value): self
    {
        return new self(CompanyText::lines($attribute, $value, self::MAX));
    }

    public static function reconstitute(string $value): self
    {
        return new self($value);
    }
}
