<?php

declare(strict_types=1);

namespace Shared\Application;

use InvalidArgumentException;

final readonly class Actor
{
    private function __construct(
        public ActorType $type,
        public ?string $id,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public static function staff(string $id): self
    {
        return new self(ActorType::Staff, self::requireId($id));
    }

    public static function customer(string $id): self
    {
        return new self(ActorType::Customer, self::requireId($id));
    }

    private static function requireId(string $id): string
    {
        if ($id === '') {
            throw new InvalidArgumentException('A staff or customer actor needs an id.');
        }

        return $id;
    }
}
