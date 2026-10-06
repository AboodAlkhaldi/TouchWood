<?php

declare(strict_types=1);

use Modules\Access\Domain\Exception\ActionStoresBeyondReach;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Enums\AccessLevel;
use Shared\Domain\ValueObject\StoreId;

const ROLE_TEST_KSA = '01j8z3k4m5n6p7q8r9s0t1v2w3';
const ROLE_TEST_UAE = '01j8z3k4m5n6p7q8r9s0t1v2w4';
const ROLE_TEST_EGY = '01j8z3k4m5n6p7q8r9s0t1v2w5';

function storeChoice(string ...$ids): StoreChoice
{
    return StoreChoice::selected(...array_map(StoreId::fromString(...), $ids));
}

describe('role names', function () {
    it('needs both languages, trimmed', function () {
        expect(RoleName::of('  دعم ', ' Support '))->toEqual(RoleName::of('دعم', 'Support'));
    });

    it('refuses a missing language', function (string $ar, string $en) {
        RoleName::of($ar, $en);
    })->throws(InvalidAccessAttribute::class)->with([['', 'Support'], ['دعم', '  ']]);
});

describe('store choices', function () {
    it('keeps chosen stores sorted and once each', function () {
        expect(storeChoice(ROLE_TEST_UAE, ROLE_TEST_KSA, ROLE_TEST_UAE)->storeIds())->toBe([ROLE_TEST_KSA, ROLE_TEST_UAE]);
    });

    it('refuses an empty choice, and a list with all stores', function (Closure $make) {
        expect($make)->toThrow(InvalidAccessAttribute::class);
    })->with([
        'no store' => [fn () => StoreChoice::selected()],
        'all stores with a list' => [fn () => StoreChoice::of(AccessLevel::AllStores, [ROLE_TEST_KSA])],
        'not a store id' => [fn () => StoreChoice::of(AccessLevel::SelectedStores, ['ksa'])],
    ]);

    it('counts only "All stores" as every store, never every store ticked one by one', function () {
        expect(StoreChoice::allStores()->includes(storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE, ROLE_TEST_EGY)))->toBeTrue()
            ->and(storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE, ROLE_TEST_EGY)->includes(StoreChoice::allStores()))->toBeFalse()
            ->and(storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE)->includes(storeChoice(ROLE_TEST_KSA)))->toBeTrue()
            ->and(storeChoice(ROLE_TEST_KSA)->includes(storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE)))->toBeFalse();
    });

    it('covers a store it lists, and all stores cover everything', function () {
        expect(storeChoice(ROLE_TEST_KSA)->covers(StoreId::fromString(ROLE_TEST_KSA)))->toBeTrue()
            ->and(storeChoice(ROLE_TEST_KSA)->covers(StoreId::fromString(ROLE_TEST_UAE)))->toBeFalse()
            ->and(StoreChoice::allStores()->covers(StoreId::fromString(ROLE_TEST_UAE)))->toBeTrue();
    });

    it('joins two choices', function () {
        expect(storeChoice(ROLE_TEST_KSA)->union(storeChoice(ROLE_TEST_UAE))->storeIds())->toBe([ROLE_TEST_KSA, ROLE_TEST_UAE])
            ->and(storeChoice(ROLE_TEST_KSA)->union(StoreChoice::allStores())->isAllStores())->toBeTrue();
    });
});

describe('roles', function () {
    it('keeps its actions sorted and once each', function () {
        $role = Role::saved('r1', RoleLevel::Staff, RoleName::of('دعم', 'Support'), ['b.c.d', 'a.b.c', 'b.c.d']);

        expect($role->permissions())->toBe(['a.b.c', 'b.c.d'])
            ->and($role->kind())->toBe(RoleKind::Saved)
            ->and($role->personalTo())->toBeNull();
    });

    it('needs at least one action when created or changed', function (Closure $make) {
        expect($make)->toThrow(InvalidAccessAttribute::class, 'at least one action');
    })->with([
        'a saved role' => [fn () => Role::saved('r1', RoleLevel::Staff, RoleName::of('دعم', 'Support'), [])],
        'a personal role' => [fn () => Role::personal('r1', RoleLevel::Staff, 'staff-1', RoleName::of('دعم', 'Support'), [])],
        'emptied' => [fn () => Role::saved('r1', RoleLevel::Staff, RoleName::of('دعم', 'Support'), ['a.b.c'])->changePermissions([])],
    ]);

    it('still loads a stored role a removed permission left empty, so an admin can fix it', function () {
        $role = Role::reconstitute('r1', RoleKind::Saved, RoleLevel::Staff, null, RoleName::of('دعم', 'Support'), []);

        expect($role->permissions())->toBe([]);
    });

    it('belongs to one staff member exactly when it is personal', function (Closure $make) {
        expect($make)->toThrow(InvalidAccessAttribute::class);
    })->with([
        'personal without its staff member' => [fn () => Role::reconstitute('r1', RoleKind::Personal, RoleLevel::Staff, null, RoleName::of('دعم', 'Support'), ['a.b.c'])],
        'saved with a staff member' => [fn () => Role::reconstitute('r1', RoleKind::Saved, RoleLevel::Staff, 'staff-1', RoleName::of('دعم', 'Support'), ['a.b.c'])],
    ]);

    it('records what changed, once', function () {
        $role = Role::personal('r1', RoleLevel::Staff, 'staff-1', RoleName::of('دعم', 'Support'), ['a.b.c']);
        $role->rename(RoleName::of('دعم', 'Support'));
        $role->changePermissions(['a.b.c']);

        expect($role->pullChanges())->toBe([]);

        $role->rename(RoleName::of('دعم', 'Help'));
        $role->changePermissions(['a.b.c', 'd.e.f']);
        $role->changeLevel(RoleLevel::Admin);

        expect($role->pullChanges())->toBe(['name', 'permissions', 'level'])
            ->and($role->pullChanges())->toBe([]);
    });

    it('never changes a saved role\'s level', function () {
        Role::saved('r1', RoleLevel::Staff, RoleName::of('دعم', 'Support'), ['a.b.c'])->changeLevel(RoleLevel::Admin);
    })->throws(InvalidAccessAttribute::class, 'never changes');
});

describe('assignments', function () {
    it('gives each action its exception\'s stores, otherwise the store row', function () {
        $assignment = RoleAssignment::assign('s1', 'r1', storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE), ['sales.order.refund' => storeChoice(ROLE_TEST_KSA)], null, new DateTimeImmutable);

        expect($assignment->storesFor('sales.order.view')->storeIds())->toBe([ROLE_TEST_KSA, ROLE_TEST_UAE])
            ->and($assignment->storesFor('sales.order.refund')->storeIds())->toBe([ROLE_TEST_KSA]);
    });

    it('counts as the staff member\'s stores the store row alone, every exception lying inside it (amendment 59)', function () {
        $assignment = RoleAssignment::assign('s1', 'r1', storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE), ['sales.order.view' => storeChoice(ROLE_TEST_KSA)], null, new DateTimeImmutable);

        expect($assignment->staffStores()->storeIds())->toBe([ROLE_TEST_KSA, ROLE_TEST_UAE])
            ->and(RoleAssignment::assign('s1', 'r1', StoreChoice::allStores(), ['a.b.c' => storeChoice(ROLE_TEST_KSA)], null, new DateTimeImmutable)->staffStores()->isAllStores())->toBeTrue()
            // Stored before the rule, an exception beyond the row adds nothing: the old union would
            // have counted Egypt.
            ->and(RoleAssignment::reconstitute('s1', 'r1', storeChoice(ROLE_TEST_KSA), ['a.b.c' => storeChoice(ROLE_TEST_EGY)], null, new DateTimeImmutable)->staffStores()->storeIds())->toBe([ROLE_TEST_KSA]);
    });

    it('intersects store choices, every store with anything, and lists by the stores they share (amendment 59)', function () {
        expect(storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE)->intersect(storeChoice(ROLE_TEST_UAE, ROLE_TEST_EGY))?->storeIds())->toBe([ROLE_TEST_UAE])
            ->and(storeChoice(ROLE_TEST_KSA)->intersect(storeChoice(ROLE_TEST_EGY)))->toBeNull()
            ->and(StoreChoice::allStores()->intersect(storeChoice(ROLE_TEST_KSA))?->storeIds())->toBe([ROLE_TEST_KSA])
            ->and(storeChoice(ROLE_TEST_KSA)->intersect(StoreChoice::allStores())?->storeIds())->toBe([ROLE_TEST_KSA])
            ->and(StoreChoice::allStores()->intersect(StoreChoice::allStores())?->isAllStores())->toBeTrue();
    });

    it('refuses an action\'s stores outside the store row, when assigned and when reassigned (amendment 59)', function (StoreChoice $row, StoreChoice $exception) {
        expect(fn () => RoleAssignment::assign('s1', 'r1', $row, ['a.b.c' => $exception], null, new DateTimeImmutable))->toThrow(ActionStoresBeyondReach::class);

        $assignment = RoleAssignment::assign('s1', 'r1', StoreChoice::allStores(), [], null, new DateTimeImmutable);

        expect(fn () => $assignment->reassign('r1', $row, ['a.b.c' => $exception], null, new DateTimeImmutable))->toThrow(ActionStoresBeyondReach::class)
            // Refused before anything changed.
            ->and($assignment->stores()->isAllStores())->toBeTrue();
    })->with([
        'a store the row lacks' => [fn () => storeChoice(ROLE_TEST_KSA), fn () => storeChoice(ROLE_TEST_EGY)],
        'one of two outside' => [fn () => storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE), fn () => storeChoice(ROLE_TEST_UAE, ROLE_TEST_EGY)],
        'every store, over chosen stores' => [fn () => storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE), fn () => StoreChoice::allStores()],
    ]);

    it('keeps no exception equal to the store row, so a row of one store keeps none (amendment 59)', function (StoreChoice $row) {
        $assignment = RoleAssignment::assign('s1', 'r1', $row, ['a.b.c' => $row], null, new DateTimeImmutable);

        expect($assignment->exceptions())->toBe([]);

        $assignment->reassign('r1', $row, ['a.b.c' => $row], null, new DateTimeImmutable);

        expect($assignment->exceptions())->toBe([]);
    })->with([
        'one store' => [fn () => storeChoice(ROLE_TEST_KSA)],
        'two stores' => [fn () => storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE)],
        'every store' => [fn () => StoreChoice::allStores()],
    ]);

    it('drops the exceptions of actions no longer in the role', function () {
        $assignment = RoleAssignment::assign('s1', 'r1', storeChoice(ROLE_TEST_KSA, ROLE_TEST_UAE, ROLE_TEST_EGY), ['a.b.c' => storeChoice(ROLE_TEST_UAE), 'd.e.f' => storeChoice(ROLE_TEST_EGY)], null, new DateTimeImmutable);

        expect($assignment->keepExceptionsFor(['a.b.c', 'x.y.z']))->toBe(['d.e.f'])
            ->and(array_keys($assignment->exceptions()))->toBe(['a.b.c']);
    });
});
