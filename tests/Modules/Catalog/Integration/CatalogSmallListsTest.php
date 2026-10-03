<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateLabel\ActivateLabel;
use Modules\Catalog\Application\Command\ActivateLabel\ActivateLabelHandler;
use Modules\Catalog\Application\Command\ActivateWarranty\ActivateWarranty;
use Modules\Catalog\Application\Command\ActivateWarranty\ActivateWarrantyHandler;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabel;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabelHandler;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarranty;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarrantyHandler;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabel;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabelHandler;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarranty;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarrantyHandler;
use Modules\Catalog\Application\Command\DeleteWordPair\DeleteWordPair;
use Modules\Catalog\Application\Command\DeleteWordPair\DeleteWordPairHandler;
use Modules\Catalog\Application\Command\EditLabel\EditLabel;
use Modules\Catalog\Application\Command\EditLabel\EditLabelHandler;
use Modules\Catalog\Application\Command\EditWarranty\EditWarranty;
use Modules\Catalog\Application\Command\EditWarranty\EditWarrantyHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The three small lists: labels — «الشارات» (catalog.md §1.8, amendment 1(b), (e), (f)) — warranties
| (§1.9) and the shared word pairs (§1.11), each under its own job with All stores, each change
| audited by value under its list's lock.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return array{blocks: list<array<string, mixed>>}
 */
function catalogSmallListsTerms(string $text): array
{
    return ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];
}

function catalogSmallListsWarranty(string $nameEn = 'Two years', ?int $months = 24): string
{
    return app(AddWarrantyHandler::class)->handle(new AddWarranty('سنتان', $nameEn, catalogSmallListsTerms('الشروط'), catalogSmallListsTerms('Terms'), $months));
}

describe('who may change each list', function () {
    it('takes each list\'s own job with All stores, and no other', function (string $permission, Closure $change) {
        Cx::actAsStaffWith([$permission], ['sa']);
        expect($change)->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);
        expect($change)->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([$permission]);
        expect($change())->toBeString();
    })->with([
        'labels' => [CatalogPermissions::LABEL_MANAGE, fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'blue'))],
        'warranties' => [CatalogPermissions::WARRANTY_MANAGE, fn (): string => catalogSmallListsWarranty()],
        'word pairs' => [CatalogPermissions::SEARCH_WORD_MANAGE, fn (): string => app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge'))],
    ]);
});

describe('labels', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::LABEL_MANAGE]);
    });

    it('adds a label with one of the ten looks, audited under the labels\' lock', function () {
        $locks = Cx::recordLocks();
        $id = app(AddLabelHandler::class)->handle(new AddLabel('تخفيض', 'Clearance', 'red-subtle', 2));
        $label = app(LabelRepository::class)->find($id);

        expect($label?->tone()->value)->toBe('red-subtle')
            ->and(Fx::audits('catalog.label.added', $id))->toBe(1)
            ->and(array_values(array_filter((array) $locks, static fn (array $lock): bool => $lock['key'] === 'catalog:labels')))->toBe([['key' => 'catalog:labels', 'level' => 2]]);
    });

    it('refuses a look outside the ten, and a name of three words in either language', function () {
        expect(fn () => app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'purple')))->toThrow(InvalidCatalogAttribute::class, 'tone')
            ->and(fn () => app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'Brand new item', 'blue')))->toThrow(InvalidCatalogAttribute::class, 'name_en')
            ->and(fn () => app(AddLabelHandler::class)->handle(new AddLabel('وصل حديثا جدا', 'New', 'blue')))->toThrow(InvalidCatalogAttribute::class, 'name_ar')
            ->and(DB::table('catalog.labels')->count())->toBe(0);
    });

    it('edits, deactivates, activates and deletes a label, each audited, and nothing for no change', function () {
        $id = app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'blue'));

        app(EditLabelHandler::class)->handle(new EditLabel($id, 'جديد', 'New', 'blue'));
        app(EditLabelHandler::class)->handle(new EditLabel($id, 'وصل حديثا', 'Just in', 'green', 1));
        app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($id));
        app(ActivateLabelHandler::class)->handle(new ActivateLabel($id));

        expect(app(LabelRepository::class)->find($id)?->name()->en)->toBe('Just in')
            ->and(Fx::audits('catalog.label.edited', $id))->toBe(1)
            ->and(Fx::audits('catalog.label.deactivated', $id))->toBe(1)
            ->and(Fx::audits('catalog.label.activated', $id))->toBe(1);

        app(DeleteLabelHandler::class)->handle(new DeleteLabel($id));

        expect(DB::table('catalog.labels')->count())->toBe(0)
            ->and(Fx::audits('catalog.label.deleted', $id))->toBe(1)
            ->and(fn () => app(DeleteLabelHandler::class)->handle(new DeleteLabel($id)))->toThrow(ListItemNotFound::class);
    });
});

describe('warranties', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::WARRANTY_MANAGE]);
    });

    it('adds a warranty for some months, or for life', function () {
        $two = catalogSmallListsWarranty();
        $life = catalogSmallListsWarranty('Lifetime', null);

        expect(app(WarrantyRepository::class)->find($two)?->period()->months)->toBe(24)
            ->and(app(WarrantyRepository::class)->find($life)?->period()->isLifetime())->toBeTrue()
            ->and(Fx::audits('catalog.warranty.added'))->toBe(2);
    });

    it('refuses a period outside 1 to 600 months, and terms that are not formatted text', function () {
        expect(fn () => catalogSmallListsWarranty('None', 0))->toThrow(InvalidCatalogAttribute::class, 'period_months')
            ->and(fn () => catalogSmallListsWarranty('Too long', 601))->toThrow(InvalidCatalogAttribute::class, 'period_months')
            ->and(fn () => app(AddWarrantyHandler::class)->handle(new AddWarranty('سنة', 'One year', ['text' => 'x'], catalogSmallListsTerms('Terms'), 12)))->toThrow(InvalidCatalogAttribute::class, 'terms_ar')
            ->and(DB::table('catalog.warranties')->count())->toBe(0);
    });

    it('edits, deactivates, activates and deletes a warranty, each audited', function () {
        $id = catalogSmallListsWarranty();

        app(EditWarrantyHandler::class)->handle(new EditWarranty($id, 'سنتان', 'Two years', catalogSmallListsTerms('الشروط'), catalogSmallListsTerms('Terms'), 24));
        app(EditWarrantyHandler::class)->handle(new EditWarranty($id, 'ثلاث سنوات', 'Three years', catalogSmallListsTerms('الشروط'), catalogSmallListsTerms('New terms'), 36));
        app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($id));
        app(ActivateWarrantyHandler::class)->handle(new ActivateWarranty($id));

        $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.warranty.edited')->value('changes'), true);
        ksort($changes);

        expect(array_keys($changes))->toBe(['name_ar', 'name_en', 'period_months', 'terms_en'])
            ->and($changes['period_months'])->toBe([24, 36])
            ->and(Fx::audits('catalog.warranty.edited', $id))->toBe(1)
            ->and(Fx::audits('catalog.warranty.deactivated', $id))->toBe(1)
            ->and(Fx::audits('catalog.warranty.activated', $id))->toBe(1);

        app(DeleteWarrantyHandler::class)->handle(new DeleteWarranty($id));

        expect(DB::table('catalog.warranties')->count())->toBe(0)
            ->and(Fx::audits('catalog.warranty.deleted', $id))->toBe(1);
    });
});

describe('word pairs', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);
    });

    it('keeps a pair as search compares words, in order, once whichever way it is written', function () {
        $id = app(AddWordPairHandler::class)->handle(new AddWordPair(' مُفصّلة ', 'Hinge'));
        $pair = app(WordPairRepository::class)->find($id);

        expect([$pair?->wordA, $pair?->wordB])->toBe(['hinge', 'مفصله'])
            ->and(Fx::audits('catalog.word_pair.added', $id))->toBe(1)
            ->and(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('HINGE', 'مفصلة')))->toThrow(NameTaken::class)
            ->and(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('hinge', ' Hinge ')))->toThrow(InvalidCatalogAttribute::class, 'word_b');
    });

    it('takes words the database\'s language order would sort the other way', function () {
        // By bytes "a b" comes first; the language order, ignoring the space and the hyphen, puts
        // "a-a" first — the CHECK compares as the code does.
        $id = app(AddWordPairHandler::class)->handle(new AddWordPair('a-a', 'a b'));
        $soft = app(AddWordPairHandler::class)->handle(new AddWordPair('soft-close', 'soft close'));

        expect([app(WordPairRepository::class)->find($id)?->wordA, app(WordPairRepository::class)->find($id)?->wordB])->toBe(['a b', 'a-a'])
            ->and(app(WordPairRepository::class)->find($soft)?->wordA)->toBe('soft close');
    });

    it('deletes a pair, audited, and answers one that is not there as not found', function () {
        $id = app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge'));
        app(DeleteWordPairHandler::class)->handle(new DeleteWordPair($id));

        expect(DB::table('catalog.word_pairs')->count())->toBe(0)
            ->and(Fx::audits('catalog.word_pair.deleted', $id))->toBe(1)
            ->and(fn () => app(DeleteWordPairHandler::class)->handle(new DeleteWordPair($id)))->toThrow(ListItemNotFound::class);
    });
});

describe('what the database refuses behind the code', function () {
    it('refuses a label look outside the ten and a name of three words', function (array $values, string $constraint) {
        Cx::actAsStaffWith([CatalogPermissions::LABEL_MANAGE]);
        $id = app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'blue'));

        expect(fn () => DB::transaction(fn () => DB::table('catalog.labels')->where('id', $id)->update($values)))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'a look outside the ten' => [['tone' => 'purple'], 'labels_tone'],
        'three English words' => [['name_en' => 'Brand new item'], 'labels_name_en_words'],
    ]);

    it('refuses a warranty period outside 1 to 600 months', function () {
        Cx::actAsStaffWith([CatalogPermissions::WARRANTY_MANAGE]);
        $id = catalogSmallListsWarranty();

        expect(fn () => DB::transaction(fn () => DB::table('catalog.warranties')->where('id', $id)->update(['period_months' => 0])))
            ->toThrow(QueryException::class, 'warranties_period');
    });

    it('refuses a pair out of order, a word with itself, and the same pair twice', function () {
        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);
        app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge'));
        $row = static fn (string $a, string $b): array => ['id' => strtolower((string) Str::ulid()), 'word_a' => $a, 'word_b' => $b, 'created_at' => now()];

        expect(fn () => DB::transaction(fn () => DB::table('catalog.word_pairs')->insert($row('مفصله', 'hinge'))))->toThrow(QueryException::class, 'word_pairs_ordered')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.word_pairs')->insert($row('hinge', 'hinge'))))->toThrow(QueryException::class, 'word_pairs_ordered')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.word_pairs')->insert($row('hinge', 'مفصله'))))->toThrow(QueryException::class, 'word_pairs_unique');
    });
});
