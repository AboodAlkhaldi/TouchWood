<?php

declare(strict_types=1);

use App\Http\AdminArea;
use App\Http\Middleware\HandleInertiaRequests;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\PlatformPermissions as Platform;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The panel's frame around every admin page, counted exactly (frontend.md §5; access.md amendment 65,
| owner 2026-10-08). The frame is what every page pays before it reads anything of its own: the
| session, the person's permissions, the settings, the stores, the person block. On 2026-10-07 it
| was 86 queries for someone holding seven jobs, 52 of them the menu reading the same permissions
| once per entry. A page's own budget is 15 (frontend.md §5: "15 is the page's own"); the frame is
| counted apart, here, on a page that reads nothing itself.
|
| Counted warm and as production serves it: each request from a fresh process (AdminBrowser forgets
| what the request before remembered, and the controllers kept on routes).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // The sweep of old sessions runs on 2 requests in 100 (config/session.php's lottery) and adds a
    // DELETE that lands in "session row": switched off, so a count never depends on a dice roll.
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();

    // A page behind the panel's door that asks for nothing of its own.
    Route::middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
        ->get('/admin/_test/frame', fn () => Inertia::render('Admin/ComingSoon'));
});

/**
 * Signed in through the panel's door.
 */
function adminFrameSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.2.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * Every query of one warm request for the frame-only page, with its values.
 *
 * @return list<array{sql: string, bindings: string}>
 */
function adminFrameQueries(AdminBrowser $browser): array
{
    $browser->get('/admin/_test/frame')->assertOk();

    /** @var ArrayObject<int, array{sql: string, bindings: string}> $queries */
    $queries = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => implode(' ', array_map(strval(...), $query->bindings))];
    });

    $browser->get('/admin/_test/frame')->assertOk();

    return array_values($queries->getArrayCopy());
}

/**
 * What each query read. A query the frame is not known to make is named "other", so it shows up.
 *
 * @param  list<array{sql: string, bindings: string}>  $queries
 * @return array<string, int>
 */
function adminFrameParts(array $queries): array
{
    $parts = [];

    foreach ($queries as $query) {
        $part = match (true) {
            str_contains($query['sql'], 'admin_sessions') => 'session row',
            str_contains($query['bindings'], 'access:staff-grants:') => 'permissions',
            str_contains($query['bindings'], 'platform:settings') => 'settings',
            str_contains($query['bindings'], 'platform:store-directory') => 'stores',
            str_contains($query['sql'], 'access.staff_users') => 'person block',
            str_contains($query['sql'], 'failed_jobs') => 'failed jobs count',
            str_contains($query['sql'], '"platform"."media"') => 'picture',
            default => 'other: '.$query['sql'],
        };
        $parts[$part] = ($parts[$part] ?? 0) + 1;
    }

    ksort($parts);

    return $parts;
}

/**
 * A public image whose sizes are ready, as the variants job leaves one.
 */
function adminFramePicture(): string
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

/**
 * @return array<string, Closure(): string>
 */
function adminFramePeople(): array
{
    return [
        'seven jobs in every store' => fn (): string => Fx::staffWith([
            Platform::STORE_VIEW, Platform::STORE_UPDATE, Platform::SETTINGS_VIEW, Platform::SETTINGS_UPDATE,
            Platform::MEDIA_UPLOAD, Platform::AUDIT_VIEW, AccessPermissions::CUSTOMER_VIEW,
        ], ['*']),
        'one job in one store' => fn (): string => Fx::staffWith([Platform::AUDIT_VIEW], ['sa']),
        'an admin holding every job a role can hold' => fn (): string => Fx::staffWith(array_map(
            static fn (PermissionDefinitionDto $permission): string => $permission->name,
            app(InMemoryPermissionCatalog::class)->assignable(),
        ), ['*'], RoleLevel::Admin),
        'a Super Admin' => fn (): string => Fx::staff(StaffStatus::Active, superAdmin: true),
        'one job, with a picture' => function (): string {
            $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
            DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => adminFramePicture()]);

            return $staffId;
        },
    ];
}

it('opens in its own recorded number of queries, whatever jobs the person holds', function (string $person, int $recorded, array $extra) {
    $queries = adminFrameQueries(adminFrameSignIn(adminFramePeople()[$person]()));

    // The session row read and written (2), the person's permissions (2), the settings (2), the
    // stores (2), the person block (1): 9. The menu asks the permissions the request already read,
    // once per entry, without a query - so one job or every job costs the same.
    $parts = ['session row' => 2, 'permissions' => 2, 'settings' => 2, 'stores' => 2, 'person block' => 1, ...$extra];
    ksort($parts);

    expect(adminFrameParts($queries))->toBe($parts)
        ->and(count($queries))->toBe($recorded);
})->with([
    'seven jobs' => ['seven jobs in every store', 9, []],
    'one job' => ['one job in one store', 9, []],
    // Whoever may manage failed jobs is also shown how many wait (the System entry's number,
    // frontend.md E7): one count more.
    'every job' => ['an admin holding every job a role can hold', 10, ['failed jobs count' => 1]],
    'a Super Admin' => ['a Super Admin', 10, ['failed jobs count' => 1]],
    // A picture is asked of Platform: one read of the media row.
    'a picture' => ['one job, with a picture', 10, ['picture' => 1]],
]);

it('reads the person\'s permissions once, and locks nothing, on a page that only shows', function (string $person) {
    $queries = adminFrameQueries(adminFrameSignIn(adminFramePeople()[$person]()));

    $permissionReads = array_filter($queries, fn (array $query): bool => str_starts_with($query['sql'], 'select')
        && str_contains($query['bindings'], 'access:staff-grants:'));
    $locks = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['sql']), 'for update'));

    // The version and the snapshot.
    expect($permissionReads)->toHaveCount(2)
        ->and($locks)->toBe([])
        // The guard looks at real queries: a request that read nothing would pass it over nothing.
        ->and($queries)->not->toBeEmpty();
})->with(array_keys(adminFramePeople()));

it('shows the person block in the panel\'s language, from one read of the person', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    $browser = adminFrameSignIn($staffId);
    $role = DB::table('access.role_assignments as a')->join('access.roles as r', 'r.id', '=', 'a.role_id')
        ->where('a.staff_user_id', $staffId)->value('r.name');
    /** @var array{ar: string, en: string} $names */
    $names = json_decode((string) $role, true, flags: JSON_THROW_ON_ERROR);

    $browser->setCookie(HandleInertiaRequests::LOCALE_COOKIE, 'en');
    $browser->get('/admin/_test/frame')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viewer.id', $staffId)->where('viewer.roleLabel', $names['en'])->where('locale', 'en'));

    $browser->setCookie(HandleInertiaRequests::LOCALE_COOKIE, 'ar');
    $browser->get('/admin/_test/frame')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viewer.roleLabel', $names['ar'])->where('locale', 'ar'));

    // No language chosen in this browser: the person's own decides the panel's language, from the
    // same read that fills the person block.
    $browser->forget(HandleInertiaRequests::LOCALE_COOKIE);

    expect(adminFrameParts(adminFrameQueries($browser))['person block'] ?? 0)->toBe(1);
});
