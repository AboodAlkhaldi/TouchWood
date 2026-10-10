<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Query\AdminShell\AdminShellForStaff;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The person the admin panel is shown to (frontend.md §2.2), read once per request, in one statement
| and without a row lock (amendment 65). It was read twice a page through the write side's
| repositories, `for update` - 14 queries on every admin page.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return ArrayObject<int, string>
 */
function adminShellQueries(): ArrayObject
{
    /** @var ArrayObject<int, string> $queries */
    $queries = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries[] = $query->sql;
    });

    return $queries;
}

/**
 * @return array{first: string, last: string, ar: string|null, en: string|null}
 */
function adminShellExpected(string $staffId): array
{
    $row = DB::table('access.staff_users as s')
        ->leftJoin('access.role_assignments as a', 'a.staff_user_id', '=', 's.id')
        ->leftJoin('access.roles as r', 'r.id', '=', 'a.role_id')
        ->where('s.id', $staffId)
        ->first(['s.first_name', 's.last_name', 'r.name']);

    /** @var array{ar: string, en: string}|null $names */
    $names = $row?->name === null ? null : json_decode((string) $row->name, true, flags: JSON_THROW_ON_ERROR);

    return ['first' => (string) $row?->first_name, 'last' => (string) $row?->last_name, 'ar' => $names['ar'] ?? null, 'en' => $names['en'] ?? null];
}

it('reads the person once in a request, in one statement, locking nothing', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff($staffId);
    $expected = adminShellExpected($staffId);
    $queries = adminShellQueries();
    $shell = app(AdminShellForStaff::class);

    $first = $shell->forCurrentStaff();
    $again = app(AdminShellForStaff::class)->forCurrentStaff();

    expect($queries->getArrayCopy())->toHaveCount(1)
        ->and(strtolower(implode(' ', $queries->getArrayCopy())))->not->toContain('for update')
        ->and($again)->toBe($first)
        ->and($first?->id)->toBe($staffId)
        ->and($first?->name)->toBe($expected['first'].' '.$expected['last'])
        ->and($first?->isSuperAdmin)->toBeFalse()
        ->and($first?->roleLabel('en'))->toBe($expected['en'])
        ->and($first?->roleLabel('ar'))->toBe($expected['ar'])
        ->and($expected['en'])->not->toBeNull();
});

it('gives a Super Admin no role, and nobody signed in no shell', function () {
    $superAdmin = Fx::staff(StaffStatus::Active, superAdmin: true);
    Fx::actAsStaff($superAdmin);
    $shown = app(AdminShellForStaff::class)->forCurrentStaff();

    expect($shown?->isSuperAdmin)->toBeTrue()
        ->and($shown?->roleLabel('en'))->toBeNull()
        ->and($shown?->roleLabel('ar'))->toBeNull();

    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    $queries = adminShellQueries();

    expect(app(AdminShellForStaff::class)->forCurrentStaff())->toBeNull()
        ->and($queries->getArrayCopy())->toBe([]);
});

it('keeps each person apart, so a request that signs someone in shows them their own', function () {
    $first = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    $second = Fx::staff(firstName: 'Second');
    $actors = new class(Actor::staff($first)) implements ActorContext
    {
        public function __construct(public Actor $actor) {}

        public function current(): Actor
        {
            return $this->actor;
        }
    };
    $shell = new AdminShellForStaff($actors, app(StaffReader::class), app(PlatformApi::class));

    expect($shell->forCurrentStaff()?->id)->toBe($first);

    $actors->actor = Actor::staff($second);

    expect($shell->forCurrentStaff()?->id)->toBe($second)
        ->and($shell->forCurrentStaff()?->roleLabel('en'))->toBeNull();
});

/**
 * A public image whose sizes are ready, as the variants job leaves one.
 */
function adminShellPicture(): string
{
    $id = strtolower((string) Str::ulid());
    $now = CarbonImmutable::now();

    DB::table('platform.media')->insert([
        'id' => $id, 'visibility' => 'PUBLIC', 'disk' => 'local', 'object_key' => "media/{$id}.jpg",
        'original_filename' => 'me.jpg', 'mime' => 'image/jpeg', 'bytes' => 1000, 'width' => 10, 'height' => 10,
        'checksum' => hash('sha256', $id), 'variants_status' => 'READY', 'variants_queued_at' => $now,
        'variants_generated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    return $id;
}

it('shows the picture\'s smallest size in the best format, asked of Platform', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    $picture = adminShellPicture();
    DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $picture]);
    Fx::actAsStaff($staffId);
    $expected = app(PlatformApi::class)->mediaUrls($picture)?->variants[MediaSize::Thumb->slug()][ImageFormat::Avif->extension()] ?? null;

    expect($expected)->toBeString()
        ->and(app(AdminShellForStaff::class)->forCurrentStaff()?->avatarUrl)->toBe($expected);
});

it('answers an id nobody has once, as nobody', function () {
    Fx::actAsStaff(strtolower((string) Str::ulid()));
    $queries = adminShellQueries();
    $shell = app(AdminShellForStaff::class);

    expect($shell->forCurrentStaff())->toBeNull()
        ->and($shell->forCurrentStaff())->toBeNull()
        ->and($queries->getArrayCopy())->toHaveCount(1);
});

it('finds the person however their id is written, and reads nothing for what is not an id', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAs(Actor::staff(strtoupper($staffId)));

    expect(app(AdminShellForStaff::class)->forCurrentStaff()?->id)->toBe($staffId)
        ->and(app(StaffReader::class)->shell(strtoupper($staffId))['first_name'] ?? null)->toBe(adminShellExpected($staffId)['first']);

    $queries = adminShellQueries();

    expect(app(StaffReader::class)->shell('not-an-id'))->toBeNull()
        ->and($queries->getArrayCopy())->toBe([]);
});
