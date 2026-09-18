<?php

declare(strict_types=1);

namespace Shared\Application;

use InvalidArgumentException;

/**
 * Who is acting. Every id is a ULID (owner's decision, 2026-09-18): a guest's id is the random
 * token their cart carries, an integration's id is its settings record, so replacing a provider
 * changes that record and never the audit format.
 *
 * Queued jobs act as the system. When a person's (or an integration's) action started the job,
 * the system actor carries that requester, so the audit log still shows who asked for the work.
 */
final readonly class Actor
{
    private const string ULID = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/i';

    private function __construct(
        public ActorType $type,
        public ?string $id,
        public ?Actor $requestedBy,
    ) {}

    /**
     * @param  Actor|null  $requestedBy  whose action started this system work, if anyone's
     */
    public static function system(?self $requestedBy = null): self
    {
        // Work started by other system work keeps the original requester.
        if ($requestedBy?->type === ActorType::System) {
            $requestedBy = $requestedBy->requestedBy;
        }

        return new self(ActorType::System, null, $requestedBy);
    }

    public static function staff(string $id): self
    {
        return new self(ActorType::Staff, self::requireUlid($id), null);
    }

    public static function customer(string $id): self
    {
        return new self(ActorType::Customer, self::requireUlid($id), null);
    }

    public static function guest(string $id): self
    {
        return new self(ActorType::Guest, self::requireUlid($id), null);
    }

    public static function integration(string $id): self
    {
        return new self(ActorType::Integration, self::requireUlid($id), null);
    }

    /**
     * Rebuilds an actor that was stored as its type and id, e.g. in a queued job's payload.
     */
    public static function of(ActorType $type, ?string $id): self
    {
        return match ($type) {
            ActorType::System => self::system(),
            ActorType::Staff => self::staff((string) $id),
            ActorType::Customer => self::customer((string) $id),
            ActorType::Guest => self::guest((string) $id),
            ActorType::Integration => self::integration((string) $id),
        };
    }

    private static function requireUlid(string $id): string
    {
        if (preg_match(self::ULID, $id) !== 1) {
            throw new InvalidArgumentException("An actor's id must be a ULID, \"{$id}\" is not.");
        }

        return strtolower($id);
    }
}
