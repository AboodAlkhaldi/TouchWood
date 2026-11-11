<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

use InvalidArgumentException;

/**
 * **Why a provider's adapter could not read it**, kept as a code and its values - a status code, seconds -
 * and written in the reader's language when shown, under "The provider said:" (sync.md §4.1.1,
 * stage 5 step 10), as Sync's own reasons are (`ReadFailure`, Q31). Stored in the same columns as those -
 * a read's `error`, a failing store's `last_error`, a failed read's report entry - as a JSON object, so
 * it is never mistaken for a code of Sync's or for a provider's own words: a reason stored before the
 * codes, or another adapter's plain text, is shown as it is.
 *
 * The English sentence beside it in the developers' message and the audit log is the lang file's
 * (`sync::provider_failures`); a refusal in a screen says the same in the page's language
 * (`ConnectionFailed`'s rule).
 */
final readonly class ProviderReason
{
    /** The address is not HTTPS: the token never leaves on plain HTTP. */
    public const string NOT_HTTPS = 'ODOO_NOT_HTTPS';

    /** A `429` in the Super Admin's test, which does not wait. */
    public const string TOO_MANY_CALLS = 'ODOO_TOO_MANY_CALLS';

    /** A `429` again after waiting it out once. */
    public const string TOO_MANY_CALLS_TWICE = 'ODOO_TOO_MANY_CALLS_TWICE';

    /** A `401`: the token is wrong or revoked. */
    public const string REFUSED_TOKEN = 'ODOO_REFUSED_TOKEN';

    /** A `403`: the token's user may not read Odoo's products. */
    public const string REFUSED_ACCESS = 'ODOO_REFUSED_ACCESS';

    public const string REFUSED_REQUEST = 'ODOO_REFUSED_REQUEST';

    /** A `5xx`: `status`. */
    public const string FAILED = 'ODOO_FAILED';

    /** Any other status but `200`: `status`. */
    public const string ANSWERED = 'ODOO_ANSWERED';

    public const string UNREACHABLE = 'ODOO_UNREACHABLE';

    /** Reaching it took longer than allowed: `seconds`. */
    public const string UNREACHABLE_WITHIN = 'ODOO_UNREACHABLE_WITHIN';

    /** Its answer took longer than allowed: `seconds`. */
    public const string NO_ANSWER_WITHIN = 'ODOO_NO_ANSWER_WITHIN';

    /** The request cannot be written - a control character in the address or the key. */
    public const string UNSENDABLE = 'ODOO_UNSENDABLE';

    public const string NOT_JSON = 'ODOO_NOT_JSON';

    /** The answer is JSON but not a list (sync.md §2.4). */
    public const string ANSWER_NOT_LIST = 'ODOO_ANSWER_NOT_LIST';

    /** An item of the answer is not an object with a whole-number `id`. */
    public const string ITEM_WITHOUT_ID = 'ODOO_ITEM_WITHOUT_ID';

    /** The answer runs past 5 MB (`OdooAdapter::MOST_ANSWER_BYTES`): not read on. */
    public const string ANSWER_TOO_LARGE = 'ODOO_ANSWER_TOO_LARGE';

    /** Every code, each with the values it carries. */
    public const array CODES = [
        self::NOT_HTTPS => [],
        self::TOO_MANY_CALLS => [],
        self::TOO_MANY_CALLS_TWICE => [],
        self::REFUSED_TOKEN => [],
        self::REFUSED_ACCESS => [],
        self::REFUSED_REQUEST => [],
        self::FAILED => ['status'],
        self::ANSWERED => ['status'],
        self::UNREACHABLE => [],
        self::UNREACHABLE_WITHIN => ['seconds'],
        self::NO_ANSWER_WITHIN => ['seconds'],
        self::UNSENDABLE => [],
        self::NOT_JSON => [],
        self::ANSWER_NOT_LIST => [],
        self::ITEM_WITHOUT_ID => [],
        self::ANSWER_TOO_LARGE => [],
    ];

    /**
     * @param  array<string, int>  $values  exactly the values its code carries
     */
    public function __construct(
        public string $code,
        public array $values = [],
    ) {
        $names = self::CODES[$code] ?? null;
        $given = array_keys($values);
        sort($given);

        if ($names === null || $given !== self::sorted($names)) {
            throw new InvalidArgumentException("Not a provider reason: {$code}.");
        }
    }

    /** As stored: `{"code":"ODOO_FAILED","values":{"status":503}}`. */
    public function stored(): string
    {
        return json_encode(['code' => $this->code, 'values' => (object) $this->values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether it was stored as an adapter's reason - a JSON object with a text `code` and its `values` - whether
     * or not this version still has that code or its values.
     */
    public static function looksStored(string $stored): bool
    {
        if (! str_starts_with($stored, '{')) {
            return false;
        }

        $read = json_decode($stored, true);

        return is_array($read) && is_string($read['code'] ?? null) && array_key_exists('values', $read);
    }

    /** A stored reason read back; null for anything else - a code of Sync's, or words as they came. */
    public static function fromStored(?string $stored): ?self
    {
        if ($stored === null || ! str_starts_with($stored, '{')) {
            return null;
        }

        $read = json_decode($stored, true);

        if (! is_array($read) || ! is_string($read['code'] ?? null) || ! is_array($read['values'] ?? null)) {
            return null;
        }

        $values = [];

        foreach ($read['values'] as $name => $value) {
            if (! is_string($name) || ! is_int($value)) {
                return null;
            }

            $values[$name] = $value;
        }

        try {
            return new self($read['code'], $values);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
