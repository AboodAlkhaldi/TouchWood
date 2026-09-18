<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use InvalidArgumentException;

/**
 * What an audited change did, attribute by attribute.
 *
 * Personal fields (name, email, phone, address) are recorded only as "changed": personal()
 * takes no value, and changed() refuses an attribute named like personal data, so account
 * anonymization never has to rewrite audit history (Platform spec §1.5).
 */
final class AuditChanges
{
    /**
     * An attribute named like personal data (owner's decision, 2026-09-18). A bare "name" is not on
     * the list, because store and currency names are audited with their values: a person's name must
     * be recorded with personal() by its module.
     */
    private const string PERSONAL_NAME = '/(^|_)(e_?mail|phone|mobile|address|(first|middle|last|family|given|full)_name|national_id|passport(_number)?|birth_?date|date_of_birth|iban)\z/i';

    /**
     * @var array<string, array{0: mixed, 1: mixed}|'changed'>
     */
    private array $changes = [];

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  bool|int|float|string|array<array-key, bool|int|float|string|null>|null  $from
     * @param  bool|int|float|string|array<array-key, bool|int|float|string|null>|null  $to
     */
    public function changed(string $attribute, bool|int|float|string|array|null $from, bool|int|float|string|array|null $to): self
    {
        if (preg_match(self::PERSONAL_NAME, $attribute) === 1) {
            throw new InvalidArgumentException("\"{$attribute}\" looks like personal data. The audit log is kept forever, so record it with personal(), which keeps only that it changed.");
        }

        $this->changes[$attribute] = [$from, $to];

        return $this;
    }

    public function personal(string $attribute): self
    {
        $this->changes[$attribute] = 'changed';

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}|'changed'>
     */
    public function toArray(): array
    {
        return $this->changes;
    }
}
