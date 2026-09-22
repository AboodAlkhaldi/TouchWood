<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStore;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStoreHandler;
use Modules\Access\Application\Query\CurrentStore\CurrentStoreForStaff;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function chooseStore(string $storeCode): void
{
    app(ChooseCurrentStoreHandler::class)->handle(new ChooseCurrentStore(Fx::storeId($storeCode)));
}

function rememberedStore(string $staffId): ?string
{
    return app(StaffUserRepository::class)->currentStore($staffId);
}

/**
 * Stage 2b, P3. The admin panel carries no store in its URLs, so the store a person is working in
 * is remembered on their account (owner, 2026-09-19). It is a preference, never permission.
 */
describe('the store a staff member is working in', function () {
    it('remembers one of their own stores, without auditing it or ending their sessions', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsStaff($staffId);
        $before = DB::table('access.staff_users')->where('id', $staffId)->first(['session_version', 'updated_at']);
        // The fixture audited the role it gave them; what matters is that choosing adds nothing.
        $entries = DB::table('platform.audit_entries')->count();

        chooseStore('sa');

        $after = DB::table('access.staff_users')->where('id', $staffId)->first(['session_version', 'updated_at']);

        expect(rememberedStore($staffId))->toBe(Fx::storeId('sa'))
            // Switching store many times a day must not fill the audit log, end a session, or read
            // as a change to the account (owner, 2026-09-22).
            ->and(DB::table('platform.audit_entries')->count())->toBe($entries)
            ->and($after?->session_version)->toBe($before?->session_version)
            ->and($after?->updated_at)->toBe($before?->updated_at);
    });

    it('refuses a store that is not theirs, and one that does not exist, in the same words', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsStaff($staffId);

        // The panel never confirms which store ids are real.
        expect(fn () => chooseStore('ae'))->toThrow(InvalidAccessAttribute::class, 'not one of your stores')
            ->and(fn () => app(ChooseCurrentStoreHandler::class)->handle(new ChooseCurrentStore(strtolower((string) Str::ulid()))))
            ->toThrow(InvalidAccessAttribute::class, 'not one of your stores')
            ->and(rememberedStore($staffId))->toBeNull();
    });

    it('refuses a store id that is not an id at all, rather than failing', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));

        // It arrives from a form, so a malformed value is answered, not thrown at the person as a
        // server error (review of step 0).
        expect(fn () => app(ChooseCurrentStoreHandler::class)->handle(new ChooseCurrentStore('not-a-store-id')))
            ->toThrow(InvalidAccessAttribute::class, 'not one of your stores');
    });

    it('refuses a Super Admin a store id that is no store at all', function () {
        // A Super Admin covers every store, so the only thing standing between them and a made-up
        // id is that the store must exist.
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(ChooseCurrentStoreHandler::class)->handle(new ChooseCurrentStore(strtolower((string) Str::ulid()))))
            ->toThrow(InvalidAccessAttribute::class, 'not one of your stores');
    });

    it('lets a Super Admin choose any store, holding no role at all', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        chooseStore('ae');

        expect(rememberedStore(Fx::asSystem(fn (): string => (string) DB::table('access.staff_users')->where('is_super_admin', true)->value('id'))))
            ->toBe(Fx::storeId('ae'));
    });

    it('follows a store an exception adds, not only the row chosen for them', function () {
        // Their role's stores are KSA; one action reaches the UAE too (access.md §1.5).
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], exceptions: [PlatformPermissions::STORE_UPDATE => ['sa', 'ae']]);
        Fx::actAsStaff($staffId);

        chooseStore('ae');

        expect(rememberedStore($staffId))->toBe(Fx::storeId('ae'));
    });

    it('is refused to a customer and to a guest', function () {
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => chooseStore('sa'))->toThrow(Unauthorized::class);

        Fx::actAsCustomer(Fx::customer());

        expect(fn () => chooseStore('sa'))->toThrow(Unauthorized::class);
    });

    it('opens in the remembered store while it is still theirs', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa', 'ae']));
        chooseStore('ae');

        $opening = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($opening->storeId)->toBe(Fx::storeId('ae'))
            ->and($opening->fellBack)->toBeFalse();
    });

    it('falls back to their first store, and says so, when the remembered one is taken away', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa', 'ae']);
        Fx::actAsStaff($staffId);
        chooseStore('ae');

        // An admin narrows their role afterwards. The preference is never trusted on the way out.
        Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa']);
        Fx::actAsStaff($staffId);
        $opening = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($opening->storeId)->toBe(Fx::storeId('sa'))
            ->and($opening->fellBack)->toBeTrue()
            // The column still holds the old store; what changed is that nobody trusts it.
            ->and(rememberedStore($staffId))->toBe(Fx::storeId('ae'));
    });

    it('says nothing about falling back to someone who never chose a store', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));

        $opening = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($opening->storeId)->toBe(Fx::storeId('sa'))
            ->and($opening->fellBack)->toBeFalse();
    });

    it('opens a Super Admin in the first store of all, having no role at all', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $opening = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($opening->storeId)->toBe(Fx::storeId('sa'))
            ->and($opening->fellBack)->toBeFalse();
    });

    it('would forget the store if one were ever closed: the key empties the column', function () {
        // A store is never deleted today - it is created by a console command and the audit log is
        // append-only, so anything audited against a store pins it for good. The key is the
        // backstop for the day that changes, and what it promises is asserted rather than assumed:
        // SET NULL, so closing a store can never block a person from using the panel.
        // Pinned to the column, not merely to the pair of tables: another key from staff_users to
        // stores would otherwise answer this question in place of the one being asked about
        // (review of step 0).
        $rule = DB::selectOne(<<<'SQL'
            SELECT c.confdeltype
            FROM pg_constraint c
            JOIN pg_class referenced ON referenced.oid = c.confrelid
            JOIN pg_namespace n ON n.oid = referenced.relnamespace
            WHERE c.conrelid = 'access.staff_users'::regclass
              AND c.contype = 'f'
              AND n.nspname = 'platform'
              AND referenced.relname = 'stores'
              AND c.conkey = ARRAY[(
                  SELECT a.attnum FROM pg_attribute a
                  WHERE a.attrelid = 'access.staff_users'::regclass AND a.attname = 'current_store_id'
              )]::smallint[]
            SQL);

        // 'n' is ON DELETE SET NULL; 'a' would be NO ACTION, 'r' RESTRICT, 'c' CASCADE.
        expect($rule?->confdeltype)->toBe('n');
    });
});
