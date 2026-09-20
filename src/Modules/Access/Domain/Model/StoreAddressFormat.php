<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\ValueObject\AddressField;

/**
 * One store's address shape (spec §1.9, amendment 41): the fields it asks for and how an address is
 * printed. It is data — staff change a store's copy without a deploy — so everything a customer
 * sends is checked against it here, before the database ever sees the row.
 *
 * A key the format does not define is refused: a mistyped key would otherwise store what no screen
 * and no shipping label can show.
 */
final readonly class StoreAddressFormat
{
    private const int TEMPLATE_MAX = 2000;

    /** A country's form, however detailed, is a form a person fills in (review of step 5). */
    private const int FIELDS_MAX = 60;

    /** Everything one address holds, so a form of long fields cannot fill the table. */
    private const int VALUES_MAX = 4000;

    /**
     * @param  list<AddressField>  $fields  in the order they are asked for
     */
    private function __construct(
        public string $storeId,
        public array $fields,
        public string $displayTemplate,
    ) {}

    /**
     * @param  list<AddressField>  $fields
     *
     * @throws InvalidAddress
     */
    public static function of(string $storeId, array $fields, string $displayTemplate): self
    {
        if ($fields === []) {
            throw new InvalidAddress('fields', 'a format has at least one field');
        }

        if (count($fields) > self::FIELDS_MAX) {
            throw new InvalidAddress('fields', 'at most '.self::FIELDS_MAX.' fields');
        }

        $keys = array_map(static fn (AddressField $field): string => $field->key, $fields);

        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidAddress('fields', 'each field appears once');
        }

        if (mb_strlen($displayTemplate) > self::TEMPLATE_MAX) {
            throw new InvalidAddress('display_template', 'at most '.self::TEMPLATE_MAX.' characters');
        }

        foreach (self::placeholders($displayTemplate) as $placeholder) {
            if (! in_array($placeholder, $keys, true)) {
                throw new InvalidAddress('display_template', "{$placeholder} is not a field of this format");
            }
        }

        usort($fields, static fn (AddressField $a, AddressField $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return new self($storeId, $fields, $displayTemplate);
    }

    /**
     * The values to store: trimmed, empty ones dropped, in the format's own order.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     *
     * @throws InvalidAddress
     */
    public function accept(array $values): array
    {
        $kept = [];
        $size = 0;

        foreach ($values as $key => $value) {
            $field = $this->field($key);

            if ($field === null) {
                // Never the key itself in the error: it came from the caller (review of step 5).
                throw new InvalidAddress(self::safeKey($key), 'not a field of this store\'s address format');
            }

            $trimmed = self::text($key, $value);

            if ($trimmed === '') {
                continue;
            }

            if (mb_strlen($trimmed) > $field->maxLength) {
                throw new InvalidAddress($key, "at most {$field->maxLength} characters");
            }

            $size += mb_strlen($trimmed);

            if ($size > self::VALUES_MAX) {
                throw new InvalidAddress('fields', 'at most '.self::VALUES_MAX.' characters in all');
            }

            $kept[$key] = $trimmed;
        }

        foreach ($this->missing($kept) as $key) {
            throw new InvalidAddress($key, 'required');
        }

        $ordered = [];

        foreach ($this->fields as $field) {
            if (isset($kept[$field->key])) {
                $ordered[$field->key] = $kept[$field->key];
            }
        }

        return $ordered;
    }

    /**
     * The values it knows, in the order it asks for them. Anything the format no longer defines
     * keeps its place at the end: it is still the customer's data until they save the address again.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public function order(array $values): array
    {
        $ordered = [];

        foreach ($this->fields as $field) {
            if (isset($values[$field->key])) {
                $ordered[$field->key] = $values[$field->key];
            }
        }

        return [...$ordered, ...array_diff_key($values, $ordered)];
    }

    /**
     * Whether values already stored still satisfy this format — a store may have asked for a new
     * field since, shortened one, or dropped one (amendment 41). An address that does not satisfy
     * it may not be used for an order until the customer saves it again.
     *
     * @param  array<string, string>  $values
     */
    public function satisfiedBy(array $values): bool
    {
        if ($this->missing($values) !== []) {
            return false;
        }

        foreach ($values as $key => $value) {
            $field = $this->field($key);

            if ($field === null || mb_strlen(trim($value)) > $field->maxLength) {
                return false;
            }
        }

        return true;
    }

    /**
     * The address as this store prints it: `{field}` is replaced by its value, a placeholder with
     * nothing in it disappears, and a line left with nothing on it is dropped. Only text is
     * replaced — nothing in a template is executed.
     *
     * @param  array<string, string>  $values
     */
    public function render(array $values): string
    {
        $lines = [];

        foreach (preg_split('/\R/', $this->displayTemplate) ?: [] as $line) {
            $filled = (string) preg_replace_callback(
                '/\{([a-z][a-z0-9_]*)\}/',
                static fn (array $match): string => $values[$match[1]] ?? '',
                $line,
            );

            // A line that lost its values keeps only punctuation and spaces: drop it.
            if (preg_replace('/[\s,;|\-\/]+/u', '', $filled) === '') {
                continue;
            }

            $lines[] = trim((string) preg_replace(['/\s*,\s*(?=,)/u', '/(^[\s,]+)|([\s,]+$)/u', '/\s{2,}/u'], ['', '', ' '], $filled));
        }

        return implode("\n", $lines);
    }

    public function field(string $key): ?AddressField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label_ar: string, label_en: string, required: bool, max_length: int, order: int}>
     */
    public function toArray(): array
    {
        return array_map(static fn (AddressField $field): array => $field->toArray(), $this->fields);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string> the required fields these values do not carry
     */
    private function missing(array $values): array
    {
        $missing = [];

        foreach ($this->fields as $field) {
            if ($field->required && trim($values[$field->key] ?? '') === '') {
                $missing[] = $field->key;
            }
        }

        return $missing;
    }

    /**
     * One value, as it may be stored: real text, on one line. A byte that is not UTF-8 would break
     * both the column and every reader of it; a newline or a control character would add a line to
     * a shipping label nobody wrote (review of step 5).
     *
     * @throws InvalidAddress
     */
    private static function text(string $key, string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidAddress($key, 'text');
        }

        // Trimmed first: a line pasted from elsewhere often ends in a newline, which is not the
        // customer's mistake. Anything left is a control character in the middle of the value.
        $trimmed = trim($value);

        if (preg_match('/\p{Cc}/u', $trimmed) === 1) {
            throw new InvalidAddress($key, 'on one line, without control characters');
        }

        return $trimmed;
    }

    /**
     * A key to put in an error: the caller's own, when it looks like a key at all.
     */
    private static function safeKey(string $key): string
    {
        return preg_match('/\A[a-z][a-z0-9_]{1,39}\z/', $key) === 1 ? $key : 'field';
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $template): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $template, $found);

        return array_values(array_unique($found[1]));
    }
}
