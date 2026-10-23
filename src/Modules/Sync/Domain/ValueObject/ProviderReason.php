<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

use InvalidArgumentException;

/**
 * **Why a provider's adapter could not read it**, kept as a code and its values - a status code, seconds,
 * pages - and written in the reader's language when shown, under "The provider said:" (sync.md §4.1.1,
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
    /** Odoo's items run past the most pages a read asks: `pages`. */
    public const string TOO_MANY_PAGES = 'ODOO_TOO_MANY_PAGES';

    /** The address is not HTTPS: the token never leaves on plain HTTP. */
    public const string NOT_HTTPS = 'ODOO_NOT_HTTPS';

    /** A `429` in the Super Admin's test, which does not wait. */
    public const string TOO_MANY_CALLS = 'ODOO_TOO_MANY_CALLS';

    /** A `429` again after waiting it out once. */
    public const string TOO_MANY_CALLS_TWICE = 'ODOO_TOO_MANY_CALLS_TWICE';

    public const string REFUSED_TOKEN = 'ODOO_REFUSED_TOKEN';

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

    public const string ITEMS_NOT_LIST = 'ODOO_ITEMS_NOT_LIST';

    public const string ITEM_WITHOUT_CODE = 'ODOO_ITEM_WITHOUT_CODE';

    public const string EMPTY_PAGE_NAMES_NEXT = 'ODOO_EMPTY_PAGE_NAMES_NEXT';

    public const string PAGES_REPEAT = 'ODOO_PAGES_REPEAT';

    public const string NEXT_NOT_TEXT = 'ODOO_NEXT_NOT_TEXT';

    /** Every code, each with the values it carries. */
    public const array CODES = [
        self::TOO_MANY_PAGES => ['pages'],
        self::NOT_HTTPS => [],
        self::TOO_MANY_CALLS => [],
        self::TOO_MANY_CALLS_TWICE => [],
        self::REFUSED_TOKEN => [],
        self::REFUSED_REQUEST => [],
        self::FAILED => ['status'],
        self::ANSWERED => ['status'],
        self::UNREACHABLE => [],
        self::UNREACHABLE_WITHIN => ['seconds'],
        self::NO_ANSWER_WITHIN => ['seconds'],
        self::UNSENDABLE => [],
        self::NOT_JSON => [],
        self::ITEMS_NOT_LIST => [],
        self::ITEM_WITHOUT_CODE => [],
        self::EMPTY_PAGE_NAMES_NEXT => [],
        self::PAGES_REPEAT => [],
        self::NEXT_NOT_TEXT => [],
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
