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
     * An attribute named like personal data (owner's decision, 2026-09-18), anywhere in the name:
     * phone_number, billing_address_street, customer_name. Checked in snake_case, so firstName counts.
     *
     * A bare "name" is not on the list, because store and currency names are audited with their
     * values: a person's name must be recorded with personal() by its module. Nor are "city" and
     * "postal_code" (owner's decision): a shipping zone's city is not personal, so a customer address
     * must mark them personal itself.
     */
    private const string PERSONAL_NAME = '/(^|_)(e_?mails?|phones?|telephones?|mobiles?|whatsapp|address(es)?|street|iqama|national_id|id_number|passport|iban|birth_?date|date_of_birth|dob|surname|firstname|lastname|(first|middle|last|family|given|full|recipient|customer|contact|holder|cardholder|guest)_name)(_|\z)/';

    /** A time or flag about personal data is not personal data: email_verified_at, phone_confirmed. */
    private const string ABOUT_PERSONAL = '/_(verified|verified_at|confirmed|confirmed_at|changed_at|updated_at|enabled|required|visible|count|type|status)\z/';

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
        $name = strtolower((string) preg_replace('/(?<=[a-z0-9])[A-Z]/', '_$0', $attribute));

        if (preg_match(self::PERSONAL_NAME, $name) === 1 && preg_match(self::ABOUT_PERSONAL, $name) !== 1) {
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
