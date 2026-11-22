<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetProductVideos\SetProductVideos;
use Modules\Catalog\Application\Command\SetProductVideos\SetProductVideosHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\ProductVideo;
use Modules\Catalog\Public\Events\ProductChanged;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| flags-2026-11.md S4.4, catalog.md amendment 48: a product's YouTube videos - at most 10, in order, each once, each kept
| as the video's id with an optional title per language - set under `catalog.product.update`, as its gallery. The code
| refuses before the database: each constraint is dropped inside its test and the handler still refuses (lessons 15,
| 35, 36); each CHECK on a nullable column is broken with the column left NULL beside it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);
});

/**
 * @param  list<array<string, mixed>>  $videos
 */
function productVideosSet(string $productId, array $videos): void
{
    app(SetProductVideosHandler::class)->handle(new SetProductVideos($productId, $videos));
}

/** @return list<array{string, string|null, string|null}> */
function productVideosKept(string $productId): array
{
    return array_map(static fn (ProductVideo $video): array => [$video->videoId, $video->titleAr, $video->titleEn], app(ProductRepository::class)->videos($productId));
}

/** The refusal's attribute and rule, or its class when it names none. */
function productVideosRefusal(callable $work): string
{
    try {
        $work();
    } catch (InvalidCatalogAttribute $refused) {
        return "{$refused->attribute}:".($refused->context()['rule'] ?? $refused->reason);
    } catch (TooMany $refused) {
        return "{$refused->attribute}:too_many {$refused->max}";
    }

    throw new LogicException('Not refused.');
}

/**
 * The constraint a row breaks, by its name in PostgreSQL's words.
 *
 * @param  array<string, mixed>  $row
 */
function productVideosBroken(array $row): string
{
    try {
        DB::transaction(fn () => DB::table('catalog.product_videos')->insert($row));
    } catch (QueryException $refused) {
        preg_match('/(?:constraint|index) "([a-z_]+)"/', $refused->getMessage(), $found);

        return $found[1] ?? $refused->getMessage();
    }

    return 'accepted';
}

describe('a product\'s videos', function () {
    it('keeps only each video\'s id, in the order given, with its titles, and audits the list by value', function () {
        $product = Px::product();

        productVideosSet($product, [
            ['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => ' تركيب الدرج ', 'title_en' => 'Fitting the drawer'],
            ['link' => 'https://www.youtube.com/shorts/a-B_c1D2e3F?feature=share'],
        ]);

        expect(productVideosKept($product))->toBe([['dQw4w9WgXcQ', 'تركيب الدرج', 'Fitting the drawer'], ['a-B_c1D2e3F', null, null]])
            ->and(DB::table('catalog.product_videos')->where('product_id', $product)->orderBy('position')->pluck('position')->all())->toBe([0, 1])
            ->and(json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.product.videos_changed')->where('subject_id', $product)->value('changes'), true))
            ->toBe(['videos' => [null, 'dQw4w9WgXcQ (تركيب الدرج / Fitting the drawer), a-B_c1D2e3F']]);

        // The same list, the other way round: moved, audited again; sent again unchanged, nothing written.
        productVideosSet($product, [['link' => 'https://www.youtube.com/watch?v=a-B_c1D2e3F'], ['link' => 'youtu.be/dQw4w9WgXcQ', 'title_ar' => 'تركيب الدرج', 'title_en' => 'Fitting the drawer']]);
        productVideosSet($product, [['link' => 'https://youtube.com/embed/a-B_c1D2e3F'], ['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'تركيب الدرج', 'title_en' => 'Fitting the drawer']]);

        expect(array_column(productVideosKept($product), 0))->toBe(['a-B_c1D2e3F', 'dQw4w9WgXcQ'])
            ->and(Fx::audits('catalog.product.videos_changed', $product))->toBe(2);
    });

    it('takes them all away when none is sent, the audit to none', function () {
        $product = Px::product();
        productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ']]);
        productVideosSet($product, []);

        expect(productVideosKept($product))->toBe([])
            ->and(json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.product.videos_changed')->where('subject_id', $product)->orderByDesc('id')->value('changes'), true))
            ->toBe(['videos' => ['dQw4w9WgXcQ', null]]);
    });

    it('refuses anything but a link to a YouTube video, "Paste a YouTube link.", writing nothing', function (mixed $sent) {
        $product = Px::product();
        productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ']]);

        expect(productVideosRefusal(fn () => productVideosSet($product, [['link' => 'https://youtu.be/a-B_c1D2e3F'], $sent])))->toBe('videos:youtube_link')
            ->and(productVideosKept($product))->toBe([['dQw4w9WgXcQ', null, null]])
            ->and(trans('catalog::errors.invalid_catalog_attribute.rules.youtube_link', [], 'en'))->toBe('Paste a YouTube link.');
    })->with([
        'another site' => [['link' => 'https://vimeo.com/123456789']],
        'a bare id' => [['link' => 'dQw4w9WgXcQ']],
        'a channel' => [['link' => 'https://www.youtube.com/@touchwood']],
        'nothing pasted' => [['title_ar' => 'فيديو']],
        'a link that is not text' => [['link' => ['https://youtu.be/dQw4w9WgXcQ']]],
        'not a video at all' => ['https://youtu.be/dQw4w9WgXcQ'],
    ]);

    it('refuses a video twice, however its links are written', function () {
        $product = Px::product();

        expect(productVideosRefusal(fn () => productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ'], ['link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5']])))->toBe('videos:video_once')
            ->and(productVideosKept($product))->toBe([]);
    });

    it('takes 10 videos, and refuses an eleventh', function () {
        $product = Px::product();
        $links = array_map(static fn (int $n): array => ['link' => 'https://youtu.be/video'.str_pad((string) $n, 6, '0', STR_PAD_LEFT)], range(1, 11));

        expect(productVideosRefusal(fn () => productVideosSet($product, $links)))->toBe('videos:too_many 10')
            ->and(productVideosKept($product))->toBe([]);

        productVideosSet($product, array_slice($links, 0, 10));

        expect(productVideosKept($product))->toHaveCount(10);
    });

    it('holds each title to one line of at most 100 characters, a blank one none, its digits 0-9', function () {
        $product = Px::product();

        expect(productVideosRefusal(fn () => productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => str_repeat('ع', 101)]])))->toBe('video_title_ar:at most 100 characters')
            ->and(productVideosRefusal(fn () => productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_en' => str_repeat('a', 101)]])))->toBe('video_title_en:at most 100 characters')
            ->and(productVideosRefusal(fn () => productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_en' => "Two\nlines"]])))->toBe('video_title_en:on one line, without control characters');

        productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'الجزء ٢', 'title_en' => '   '], ['link' => 'https://youtu.be/a-B_c1D2e3F', 'title_ar' => str_repeat('ع', 100)]]);

        expect(productVideosKept($product))->toBe([['dQw4w9WgXcQ', 'الجزء 2', null], ['a-B_c1D2e3F', str_repeat('ع', 100), null]]);
    });

    it('is the product\'s, never a size\'s, and tells no other module: Pricing hears nothing', function () {
        $ready = Px::ready();
        Event::fake([ProductChanged::class]);
        productVideosSet($ready['product'], [['link' => 'https://youtu.be/dQw4w9WgXcQ']]);

        expect(DB::table('catalog.product_videos')->where('product_id', $ready['product'])->count())->toBe(1)
            ->and(DB::getSchemaBuilder()->getColumnListing('catalog.product_videos'))->not->toContain('variant_id');
        Event::assertNotDispatched(ProductChanged::class);
    });

    it('goes with a draft deleted: its rows are the product\'s', function () {
        $product = Px::product();
        productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ']]);
        DB::table('catalog.products')->where('id', $product)->delete();

        expect(DB::table('catalog.product_videos')->where('product_id', $product)->count())->toBe(0);
    });

    it('refuses a product that does not exist', function () {
        expect(fn () => productVideosSet(strtolower((string) Str::ulid()), [['link' => 'https://youtu.be/dQw4w9WgXcQ']]))->toThrow(ProductNotFound::class);
    });
});

describe('who may change them', function () {
    it('needs catalog.product.update, as the gallery does', function () {
        $product = Px::product();
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_VIEW]);

        expect(fn () => productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ']]))->toThrow(Unauthorized::class)
            ->and(productVideosKept($product))->toBe([]);
    });

    it('needs it in every store where the product is Active', function () {
        $ready = Px::ready();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('eg'), $ready['product'], true)));
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa']);

        expect(fn () => productVideosSet($ready['product'], [['link' => 'https://youtu.be/dQw4w9WgXcQ']]))->toThrow(Unauthorized::class)
            ->and(productVideosKept($ready['product']))->toBe([]);

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa', 'eg']);
        productVideosSet($ready['product'], [['link' => 'https://youtu.be/dQw4w9WgXcQ']]);

        expect(productVideosKept($ready['product']))->toBe([['dQw4w9WgXcQ', null, null]]);
    });
});

describe('the table\'s last line', function () {
    it('takes the base row the cases below break, its titles left NULL', function () {
        expect(productVideosBroken(['product_id' => Px::product(), 'position' => 0, 'video_id' => 'dQw4w9WgXcQ']))->toBe('accepted');
    });

    it('refuses each row its constraint is for', function (array $with, string $constraint) {
        $product = Px::product();
        DB::table('catalog.product_videos')->insert(['product_id' => $product, 'position' => 0, 'video_id' => 'a-B_c1D2e3F']);

        expect(productVideosBroken([...['product_id' => $product, 'position' => 1, 'video_id' => 'dQw4w9WgXcQ'], ...$with]))->toBe($constraint);
    })->with([
        'an eleventh place' => [['position' => 10], 'product_videos_position'],
        'a place below the first' => [['position' => -1], 'product_videos_position'],
        'an id of 10 characters' => [['video_id' => 'dQw4w9WgXc'], 'product_videos_id'],
        'an Arabic title of spaces, the English NULL' => [['title_ar' => '   '], 'product_videos_titles'],
        'an English title of spaces, the Arabic NULL' => [['title_en' => '  '], 'product_videos_titles'],
        'the same video twice' => [['video_id' => 'a-B_c1D2e3F'], 'product_videos_once'],
        'a place taken' => [['position' => 0], 'product_videos_pkey'],
    ]);

    it('refuses a title of 101 characters in the column itself', function () {
        expect(fn () => DB::transaction(fn () => DB::table('catalog.product_videos')->insert(['product_id' => Px::product(), 'position' => 0, 'video_id' => 'dQw4w9WgXcQ', 'title_en' => str_repeat('a', 101)])))->toThrow(QueryException::class);
    });

    it('refuses in the code first, with the constraint dropped behind it', function (string $drop, Closure $videos, string $refusal) {
        $product = Px::product();
        DB::statement($drop);

        expect(productVideosRefusal(fn () => productVideosSet($product, $videos())))->toBe($refusal)
            ->and(DB::table('catalog.product_videos')->count())->toBe(0);
    })->with([
        'an eleventh video' => ['ALTER TABLE catalog.product_videos DROP CONSTRAINT product_videos_position', fn (): array => array_map(static fn (int $n): array => ['link' => 'https://youtu.be/video'.str_pad((string) $n, 6, '0', STR_PAD_LEFT)], range(1, 11)), 'videos:too_many 10'],
        'an id of 10 characters' => ['ALTER TABLE catalog.product_videos DROP CONSTRAINT product_videos_id', fn (): array => [['link' => 'https://youtu.be/dQw4w9WgXc']], 'videos:youtube_link'],
        'the same video twice' => ['ALTER TABLE catalog.product_videos DROP CONSTRAINT product_videos_once', fn (): array => [['link' => 'https://youtu.be/dQw4w9WgXcQ'], ['link' => 'https://youtu.be/dQw4w9WgXcQ']], 'videos:video_once'],
    ]);

    it('turns a blank title into none in the code, so the CHECK on it is never reached: dropped, nothing breaks', function () {
        $product = Px::product();
        DB::statement('ALTER TABLE catalog.product_videos DROP CONSTRAINT product_videos_titles');
        productVideosSet($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => '  ', 'title_en' => "\t"]]);

        expect(productVideosKept($product))->toBe([['dQw4w9WgXcQ', null, null]]);
    });
});
