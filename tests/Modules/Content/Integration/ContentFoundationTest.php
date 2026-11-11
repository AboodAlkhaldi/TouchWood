<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Infrastructure\Persistence\ContentSchema;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Infrastructure\External\FakeTextTranslator;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Content's foundation (content.md §3, §5, §7; plan PR 2): its permissions in Access's catalog, named in
| both languages, in the role editor's new Content group; the content schema and its slug history; every
| error's words in both languages; what its screens may offer.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Content declared with that reservation, sorted
 */
function contentDeclared(bool $reserved): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'content.') && $permission->reserved === $reserved),
    ));
    sort($names);

    return $names;
}

describe('the permissions', function () {
    // Two literal lists, split by the reservation, so a name moved from one to the other is caught.
    it('declares the nine jobs of §3 as jobs, and nothing else', function () {
        expect(contentDeclared(reserved: false))->toBe([
            'content.banner.manage',
            'content.bar.manage',
            'content.blog.manage',
            'content.campaign.manage',
            'content.homepage.manage',
            'content.page.manage',
            'content.popup.manage',
            'content.project.manage',
            'content.seo.manage',
        ]);
    });

    it('reserves exactly themes, redirects and the system\'s three', function () {
        expect(contentDeclared(reserved: true))->toBe([
            'content.campaigns.advance',
            'content.redirect.manage',
            'content.redirects.check',
            'content.sitemap.build',
            'content.theme.manage',
        ]);
    });

    it('makes each job per store, offered under Content, open to any role', function (string $name) {
        $permission = app(InMemoryPermissionCatalog::class)->definition($name);

        expect($permission?->audience)->toBe(PermissionAudience::Role)
            ->and($permission?->kind)->toBe(PermissionKind::PerStore)
            ->and($permission?->group)->toBe(PermissionGroup::Content)
            ->and($permission?->adminOnly)->toBeFalse()
            ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
    })->with(ContentPermissions::jobs());

    it('lets a staff role hold every job', function () {
        $roleId = Fx::role(ContentPermissions::jobs(), RoleLevel::Staff);

        expect(Fx::rolePermissions($roleId))->toEqualCanonicalizing(ContentPermissions::jobs());
    });

    it('keeps themes per store, checked in every store; redirects and the system\'s jobs store-free; none offered', function (string $name, PermissionKind $kind) {
        $permission = app(InMemoryPermissionCatalog::class)->definition($name);

        expect($permission?->reserved)->toBeTrue()
            ->and($permission?->kind)->toBe($kind)
            ->and($permission?->group)->toBeNull()
            ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain($name);
    })->with([
        [ContentPermissions::THEME_MANAGE, PermissionKind::PerStore],
        [ContentPermissions::REDIRECT_MANAGE, PermissionKind::Global],
        [ContentPermissions::CAMPAIGNS_ADVANCE, PermissionKind::Global],
        [ContentPermissions::SITEMAP_BUILD, PermissionKind::Global],
        [ContentPermissions::REDIRECTS_CHECK, PermissionKind::Global],
    ]);

    it('names the group and every permission in English and in Arabic', function () {
        expect(trans(PermissionGroup::Content->labelKey(), [], 'en'))->toBe('Content')
            ->and(trans('content::permissions.homepage.manage', [], 'en'))->toBe('Manage the store\'s home page and footer');

        // Arabic read with no fallback, so a line missing in Arabic cannot pass on the English one.
        app('translator')->setFallback('ar');

        expect(trans(PermissionGroup::Content->labelKey(), [], 'ar'))->toBe('المحتوى');

        foreach (array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'content.')) as $permission) {
            foreach (['ar', 'en'] as $locale) {
                $label = trans($permission->labelKey(), [], $locale);

                expect(is_string($label) && $label !== $permission->labelKey())->toBeTrue("{$permission->name} has no {$locale} name");
            }
        }
    });

    it('gives each kind Translate is pressed in the permission that edits it', function () {
        expect(ContentPermissions::forKind('page'))->toBe(ContentPermissions::PAGE_MANAGE)
            ->and(ContentPermissions::forKind('theme'))->toBe(ContentPermissions::THEME_MANAGE)
            ->and(ContentPermissions::forKind('redirect'))->toBeNull()
            ->and(array_diff(array_values(ContentPermissions::KINDS), [...ContentPermissions::jobs(), ContentPermissions::THEME_MANAGE]))->toBe([])
            ->and(array_diff(ContentPermissions::jobs(), array_values(ContentPermissions::KINDS)))->toBe([]);
    });
});

describe('the schema', function () {
    it('creates the content schema, on the search path so a fresh migration wipes it', function () {
        expect(DB::table('information_schema.schemata')->where('schema_name', 'content')->exists())->toBeTrue()
            ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('content');
    });

    // A test database keeps its schemas from one run to the next, so this starts from nothing; PostgreSQL's
    // DDL is transactional, so the test's transaction puts it back.
    it('creates the schema from nothing, and its rollback drops it', function () {
        $exists = static fn (): bool => DB::table('information_schema.schemata')->where('schema_name', 'content')->exists();

        DB::statement('DROP SCHEMA content CASCADE');
        expect($exists())->toBeFalse();

        ContentSchema::create();
        expect($exists())->toBeTrue();

        ContentSchema::drop();
        expect($exists())->toBeFalse();
    });

    it('holds slug_history, the own items\' tables (PR 3), SEO\'s and the redirects\' (PR 4) and the campaigns\' and themes\' (PR 6)', function () {
        $tables = array_map(strval(...), DB::table('information_schema.tables')->where('table_schema', 'content')->pluck('table_name')->all());
        // Sorted by bytes, as the list is written: the database's language order skips underscores (lesson 132).
        sort($tables, SORT_STRING);

        expect($tables)->toBe([
            'banner_images', 'banners', 'bars', 'blog_categories', 'blog_post_products', 'blog_post_translations', 'blog_posts',
            'campaign_run_targets', 'campaign_runs', 'campaigns',
            'footers', 'home_section_media', 'home_sections', 'job_runs', 'page_images', 'page_translations', 'pages', 'popups', 'post_images',
            'project_images', 'project_photos', 'project_products', 'project_translations', 'projects', 'redirect_uploads', 'redirects',
            'seo_defaults', 'sitemaps', 'slug_history', 'smart_bars', 'theme_part_images', 'theme_parts', 'theme_stores', 'themes', 'unread_translations', 'waiting_jobs',
        ]);
    });
});

/**
 * A row of slug history, any column replaced.
 *
 * @param  array<string, mixed>  $with
 */
function contentSlugRow(array $with = []): void
{
    DB::table('content.slug_history')->insert([
        'id' => strtolower((string) Str::ulid()),
        'store_id' => Fx::storeId('sa'),
        'kind' => 'PAGE',
        'item_id' => strtolower((string) Str::ulid()),
        'locale' => 'ar',
        'slug' => 'من-نحن',
        'created_at' => now(),
        ...$with,
    ]);
}

describe('the slug history\'s last line of defence', function () {
    it('keeps a row of every kind with a shop address, in both languages', function (string $kind, string $locale) {
        contentSlugRow(['kind' => $kind, 'locale' => $locale, 'slug' => strtolower($kind).'-'.$locale]);

        expect(DB::table('content.slug_history')->count())->toBe(1);
    })->with(['PAGE', 'POST', 'PROJECT', 'BLOG_CATEGORY', 'CAMPAIGN'], ['ar', 'en']);

    it('refuses a value outside its rules, NULL included', function (array $with, string $constraint) {
        expect(fn () => contentSlugRow($with))->toThrow(QueryException::class, $constraint);
    })->with([
        'a kind of no shop address' => [['kind' => 'BANNER'], 'slug_history_kind'],
        'a language of no shop' => [['locale' => 'fr'], 'slug_history_locale'],
        'an empty address' => [['slug' => ''], 'slug_history_slug'],
        'an address past 200 characters' => [['slug' => str_repeat('a', 201)], 'slug_history_slug'],
        'an address with a slash' => [['slug' => 'a/b'], 'slug_history_slug'],
        'no kind' => [['kind' => null], 'kind'],
        'no language' => [['locale' => null], 'locale'],
        'no address' => [['slug' => null], 'slug'],
        'no item' => [['item_id' => null], 'item_id'],
        'no store' => [['store_id' => null], 'store_id'],
        'a store that is none' => [['store_id' => strtolower((string) Str::ulid())], 'slug_history_store'],
    ]);

    it('holds one address to one item per store, kind and language', function () {
        contentSlugRow();

        // In a savepoint of its own: a refused row would otherwise abort the test's whole transaction.
        expect(fn () => DB::transaction(fn () => contentSlugRow()))->toThrow(QueryException::class, 'slug_history_unique');

        // Another language, kind or store may hold the same words.
        contentSlugRow(['locale' => 'en']);
        contentSlugRow(['kind' => 'POST']);
        contentSlugRow(['store_id' => Fx::storeId('ae')]);
        expect(DB::table('content.slug_history')->count())->toBe(4);
    });
});

describe('the errors', function () {
    it('gives every error of §7 its words in both languages', function () {
        $classes = glob(base_path('src/Modules/Content/Domain/Exception/*.php')) ?: [];
        $named = 0;

        foreach ($classes as $file) {
            $class = 'Modules\\Content\\Domain\\Exception\\'.basename($file, '.php');

            if (! is_subclass_of($class, ContentError::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $error = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            [$module, $name] = explode('.', $error->type(), 2);

            expect($module)->toBe('content');

            foreach (['ar', 'en'] as $locale) {
                foreach (['title', 'detail'] as $part) {
                    $key = "content::errors.{$name}.{$part}";
                    expect(trans($key, [], $locale))->not->toBe($key, "{$class} has no {$locale} {$part}");
                }
            }

            $named++;
        }

        // §7 lists nineteen, TranslationUnread (P14) among them, and PR 6's review adds a schedule stopped part way; a moved folder must not pass over nothing.
        expect($named)->toBe(20);
    });
});

describe('what the screens may offer', function () {
    it('answers, per store, the kinds the reader may edit there, and whether Translate can work', function () {
        Fx::actAsStaff(Fx::staffWith([ContentPermissions::PAGE_MANAGE, ContentPermissions::BLOG_MANAGE], ['sa']));
        $actions = app(ContentActionsForReader::class);

        expect(array_map(static fn ($store): string => $store->storeId, $actions->stores()))->toBe([Fx::storeId('sa')])
            ->and($actions->forStore(Fx::storeId('sa'))?->kinds)->toBe(['page', 'blog'])
            ->and($actions->forStore(Fx::storeId('sa'))?->may('banner'))->toBeFalse()
            ->and($actions->forStore(Fx::storeId('ae')))->toBeNull()
            ->and($actions->mayManageThemes())->toBeFalse()
            ->and($actions->mayManageRedirects())->toBeFalse()
            ->and($actions->translationReady())->toBeTrue();

        app(FakeTextTranslator::class)->notReady();
        expect(app(ContentActionsForReader::class)->translationReady())->toBeFalse();
    });

    it('offers a Super Admin every kind in every store, themes and redirects, and an off store marked off', function () {
        $eg = Fx::storeId('eg');
        DB::table('platform.stores')->where('id', $eg)->update(['is_active' => false]);
        app(StoreDirectory::class)->invalidate();
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $actions = app(ContentActionsForReader::class);

        expect($actions->forStore($eg)?->off)->toBeTrue()
            ->and($actions->forStore($eg)?->kinds)->toBe(['homepage', 'bar', 'page', 'banner', 'campaign', 'popup', 'blog', 'project', 'seo'])
            ->and($actions->mayManageThemes())->toBeTrue()
            ->and($actions->mayManageRedirects())->toBeTrue();
    });

    it('offers an off store to nobody who may not switch stores', function () {
        $eg = Fx::storeId('eg');
        $editor = Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['eg']);
        DB::table('platform.stores')->where('id', $eg)->update(['is_active' => false]);
        app(StoreDirectory::class)->invalidate();
        Fx::actAsStaff($editor);

        expect(app(ContentActionsForReader::class)->stores())->toBe([]);
    });
});
