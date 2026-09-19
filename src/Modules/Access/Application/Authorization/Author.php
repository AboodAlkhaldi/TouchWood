<?php

declare(strict_types=1);

namespace Modules\Access\Application\Authorization;

use Modules\Access\Domain\ValueObject\StoreChoice;

/**
 * Who is making a change to roles or staff, for the "nobody grants more than they hold" rule.
 * The system (a console command) and Super Admins are unlimited.
 */
final readonly class Author
{
    private function __construct(
        public ?string $staffId,
        private ?StaffGrants $grants,
    ) {}

    public static function unlimited(?string $superAdminId = null): self
    {
        return new self($superAdminId, null);
    }

    public static function staff(StaffGrants $grants): self
    {
        return new self($grants->staffId, $grants);
    }

    public function isUnlimited(): bool
    {
        return $this->grants === null;
    }

    public function holds(string $permission): bool
    {
        return $this->grants === null || $this->grants->storesFor($permission) !== null;
    }

    /**
     * The stores this action reaches for the author: all stores when unlimited, null when they do
     * not hold it.
     */
    public function storesFor(string $permission): ?StoreChoice
    {
        return $this->grants === null ? StoreChoice::allStores() : $this->grants->storesFor($permission);
    }

    /**
     * The author's own stores: all when unlimited, null when they hold no role.
     */
    public function stores(): ?StoreChoice
    {
        return $this->grants === null ? StoreChoice::allStores() : $this->grants->stores;
    }
}
