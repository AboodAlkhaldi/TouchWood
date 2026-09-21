<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Application\Query\StaffVisibility;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who works here, as this reader may see them (spec §3.3, amendments 9 and 43). Who is visible is
 * part of the query, not a filter afterwards, so the page and the total say the same thing: a
 * total counting people the reader may not see would be a headcount of stores they do not cover.
 */
final readonly class ListStaffHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_VIEW;

    private const int PER_PAGE_MAX = 100;

    private const int PAGE_MAX = 100_000;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffReader $staff,
        private StaffVisibility $visibility,
    ) {}

    /**
     * @throws Unauthorized when they hold the permission in no store at all
     * @throws InvalidAccessAttribute when the status asked for is not one
     */
    public function handle(ListStaff $query): StaffPage
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);

        if ($stores === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);
        $page = min(max($query->page, 1), self::PAGE_MAX);
        $status = $query->status === null
            ? null
            : (StaffStatus::tryFrom($query->status) ?? throw new InvalidAccessAttribute('status', 'not a staff status'))->value;

        $unlimited = $this->rules->author()->isUnlimited();
        // Only a Super Admin sees Super Admins, and only they read a list no stores narrow.
        $mine = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);

        $found = $this->staff->staff($unlimited ? null : $mine, $unlimited, $query->search, $status, $page, $perPage);

        return new StaffPage(
            array_map(fn (array $row): StaffSummary => $this->visibility->summary($row, $unlimited), $found['rows']),
            $found['total'],
            $page,
            $perPage,
        );
    }
}
