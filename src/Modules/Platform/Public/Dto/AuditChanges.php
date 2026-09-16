<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * What an audited change did, attribute by attribute.
 *
 * Personal fields (name, email, phone, address) are recorded only as "changed": personal()
 * takes no value, so a value can never reach the audit log and account anonymization never
 * has to rewrite audit history (Platform spec §1.5).
 */
final class AuditChanges
{
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
