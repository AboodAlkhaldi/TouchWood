<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use InvalidArgumentException;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/** What Feedback's reads are asked with, read once for all of them. */
final readonly class ReadInputs
{
    public function __construct(
        private ActorContext $actors,
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /** The signed-in customer reading their own (`feedback.own.view`); anyone else is refused. */
    public function customer(string $permission): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        return strtolower($actor->id);
    }

    /**
     * A staff member reading a store's list, its permission there already asked: a store that is
     * nothing is refused; an off one only for those who may switch stores, as Feedback's changes.
     *
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function staffIn(string $permission, StoreId $store): void
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        $found = $this->platform->store($store) ?? throw new InvalidFeedbackAttribute('store', 'no such store');

        if (! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new Unauthorized($permission);
        }
    }

    /** @throws InvalidFeedbackAttribute */
    public static function store(string $storeId): StoreId
    {
        try {
            return StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidFeedbackAttribute('store', 'not a store id');
        }
    }

    /**
     * @return 'ar'|'en'
     *
     * @throws InvalidFeedbackAttribute
     */
    public static function locale(string $locale): string
    {
        return FeedbackText::locale($locale) === 'ar' ? 'ar' : 'en';
    }

    /** @throws InvalidFeedbackAttribute */
    public static function after(?string $after): ?string
    {
        if ($after === null || trim($after) === '') {
            return null;
        }

        $id = strtolower(trim($after));

        return Ulids::valid($id) ? $id : throw new InvalidFeedbackAttribute('after', 'not a place in this list');
    }
}
