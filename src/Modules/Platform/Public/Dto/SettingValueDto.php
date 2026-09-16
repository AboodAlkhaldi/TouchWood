<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use LogicException;

/**
 * A setting's current value, or its default when nothing is stored. The typed readers throw
 * when the value is not the type asked for, instead of silently coercing it.
 */
final readonly class SettingValueDto
{
    public function __construct(
        public string $key,
        public ?string $storeId,
        public bool $isDefault,
        public mixed $value,
    ) {}

    public function int(): int
    {
        return is_int($this->value) ? $this->value : throw $this->mismatch('an integer');
    }

    public function bool(): bool
    {
        return is_bool($this->value) ? $this->value : throw $this->mismatch('a boolean');
    }

    public function string(): string
    {
        return is_string($this->value) ? $this->value : throw $this->mismatch('a string');
    }

    /**
     * @return list<mixed>
     */
    public function list(): array
    {
        return is_array($this->value) && array_is_list($this->value) ? $this->value : throw $this->mismatch('a list');
    }

    private function mismatch(string $expected): LogicException
    {
        return new LogicException("The setting \"{$this->key}\" is not {$expected}: it holds ".get_debug_type($this->value).'.');
    }
}
