<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Content's audit entries (content.md §1.1 rule 13): every change in its own transaction, named `content.*` in both
 * languages (`content::audit`). **Short fields by value, from and to; a long text - a body - as changed, with its
 * length** (the log is kept forever), never its words.
 *
 * Contact lines a footer shows (its phone, email and address) are recorded as changed only: Platform's audit refuses
 * names that read as personal data, and keeping them out costs nothing.
 */
final class ContentAudit
{
    /**
     * @param  string  $action  `content.{resource}.{past tense}`, written out where the entry is made
     */
    public static function entry(string $action, string $subjectType, string $subjectId, ?string $storeId, AuditChanges $changes): AuditEntryDto
    {
        return new AuditEntryDto($action, $subjectType, strtolower($subjectId), $storeId, $changes);
    }

    /**
     * What changed between two sets of short fields, by value: a field the same on both sides is left out.
     *
     * @param  array<string, bool|int|string|null>  $before
     * @param  array<string, bool|int|string|null>  $after
     * @param  list<string>  $contact  fields recorded as changed only
     */
    public static function diff(array $before, array $after, array $contact = [], ?AuditChanges $changes = null): AuditChanges
    {
        $changes ??= AuditChanges::none();

        // Every field either side names: a created item's from null, a deleted item's to null.
        foreach (array_keys($before + $after) as $field) {
            $was = $before[$field] ?? null;
            $value = $after[$field] ?? null;

            if ($was === $value) {
                continue;
            }

            in_array($field, $contact, true) ? $changes->personal($field) : $changes->changed($field, $was, $value);
        }

        return $changes;
    }

    /**
     * Nested words as short fields, each by its path (`main.text.ar`, `facts.0.en`), for `diff()`.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, bool|int|string|null>
     */
    public static function flatten(array $value, string $prefix = ''): array
    {
        $fields = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $fields = is_array($item)
                ? [...$fields, ...self::flatten($item, $path)]
                : [...$fields, $path => is_bool($item) || is_int($item) || is_string($item) || $item === null ? $item : (string) json_encode($item)];
        }

        return $fields;
    }

    /**
     * A structured value written as one text, for `long()`: the same value always the same text.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A long text as changed, with its length before and after (`{field}_length`), or nothing when unchanged.
     */
    public static function long(AuditChanges $changes, string $field, ?string $before, ?string $after): AuditChanges
    {
        if ($before !== $after) {
            $changes->changed("{$field}_length", $before === null ? null : mb_strlen($before), $after === null ? null : mb_strlen($after));
        }

        return $changes;
    }
}
