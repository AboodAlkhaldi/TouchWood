<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\B2B\Application\Types\GiveEveryStoreTheStartingTypes;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Model\StoreTypeLists;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\TypeName;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Events\StoreCreated;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The two lists staff manage, one of each per store (b2b.md §1.3, §5, amendment 5): what every store
| starts with, and each store's "copied, not yet reviewed" flag (amendment 6(a)); what the database
| refuses; and the repositories that read and write them.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // The launch stores: created after the migrations, so their lists come from the listener.
    seed(PlatformSeeder::class);
});

/** The six company types the owner listed on 2026-09-27, in their order. */
const B2B_TYPES_COMPANY_NAMES = [
    ['مؤسسة فردية', 'Sole Proprietorship / Individual Establishment'],
    ['شركة ذات مسؤولية محدودة', 'Limited Liability Company'],
    ['شركة مساهمة', 'Joint Stock Company'],
    ['شركة مساهمة مبسطة', 'Simplified Joint Stock Company'],
    ['شركة تضامن', 'General Partnership'],
    ['شركة توصية بسيطة', 'Limited Partnership'],
];

/** The three handoff §8.1 names, each required. */
const B2B_TYPES_DOCUMENT_NAMES = [
    ['شهادة ضريبة القيمة المضافة', 'VAT certificate', true],
    ['شهادة السجل التجاري', 'Commercial registration certificate', true],
    ['هوية المفوّض بالتوقيع', 'Authorised signatory ID', true],
];

/**
 * A type row written past the code, to show what the database refuses on its own. It belongs to
 * the 'sa' store unless the values say otherwise.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 *
 * @param  array<string, mixed>  $values
 */
function b2bTypeRow(string $table, array $values = []): void
{
    DB::table("b2b.{$table}")->insert([
        'id' => strtolower((string) Str::ulid()),
        'store_id' => Fx::storeId('sa'),
        'name_ar' => 'نوع '.Str::random(6),
        'name_en' => 'Type '.Str::random(6),
        'position' => 50,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
        ...$values,
    ]);
}

/**
 * @return list<string> every store's id
 */
function b2bTypesStoreIds(): array
{
    return array_map(static fn ($store): string => $store->id, app(PlatformApi::class)->stores());
}

/**
 * One store's company types as the form lists them: [Arabic, English].
 *
 * @return list<array{0: string, 1: string}>
 */
function b2bTypesCompanyNames(string $storeId): array
{
    return array_map(static fn (CompanyType $type): array => [$type->name()->ar, $type->name()->en], app(CompanyTypeRepository::class)->all($storeId));
}

/**
 * One store's document types as the form lists them: [Arabic, English, required].
 *
 * @return list<array{0: string, 1: string, 2: bool}>
 */
function b2bTypesDocumentNames(string $storeId): array
{
    return array_map(static fn (DocumentType $type): array => [$type->name()->ar, $type->name()->en, $type->isRequired()], app(DocumentTypeRepository::class)->all($storeId));
}

/**
 * Every row of one store's two lists, as stored — to show that nothing about them changed.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function b2bTypesRows(string $storeId): array
{
    $rows = static fn (string $table): array => array_values(DB::table("b2b.{$table}")->where('store_id', $storeId)->orderBy('id')->get()
        ->map(static fn (stdClass $row): array => (array) $row)->all());

    return ['company_types' => $rows('company_types'), 'document_types' => $rows('document_types')];
}

/**
 * The store's "copied, not yet reviewed" row, as stored, or null when it has none.
 *
 * @return array<string, mixed>|null
 */
function b2bTypesFlagRow(string $storeId): ?array
{
    $row = DB::table('b2b.store_type_lists')->where('store_id', $storeId)->first();

    return $row instanceof stdClass ? (array) $row : null;
}

/**
 * The store's admins have looked at their lists (step 4's "mark reviewed").
 */
function b2bTypesMarkReviewed(string $storeId): void
{
    DB::transaction(function () use ($storeId) {
        $repository = app(StoreTypeListsRepository::class);
        $lists = $repository->byStore($storeId) ?? throw new LogicException("No lists row for {$storeId}.");
        $lists->markReviewed();
        $repository->update($lists);
    });
}

describe('what every store starts with (amendments 5 and 6(a))', function () {
    it('puts the b2b schema on the search path, so a fresh migrate wipes it', function () {
        expect(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('b2b');
    });

    it('gives every launch store the six company types and three required document types, all active, marked copied', function () {
        $storeIds = b2bTypesStoreIds();

        // The seeder creates the stores after the migrations: the listener wrote these.
        expect($storeIds)->toHaveCount(3);

        foreach ($storeIds as $storeId) {
            $companyTypes = app(CompanyTypeRepository::class)->all($storeId);
            $documentTypes = app(DocumentTypeRepository::class)->all($storeId);

            expect(b2bTypesCompanyNames($storeId))->toBe(B2B_TYPES_COMPANY_NAMES)
                ->and(b2bTypesDocumentNames($storeId))->toBe(B2B_TYPES_DOCUMENT_NAMES)
                ->and(array_map(static fn (CompanyType $type): int => $type->position(), $companyTypes))->toBe([1, 2, 3, 4, 5, 6])
                ->and(array_filter($companyTypes, static fn (CompanyType $type): bool => ! $type->isActive() || $type->inactiveDisplay() !== null))->toBe([])
                ->and(array_filter($documentTypes, static fn (DocumentType $type): bool => ! $type->isActive() || $type->inactiveDisplay() !== null))->toBe([])
                ->and(app(StoreTypeListsRepository::class)->find($storeId)?->copiedNotReviewed())->toBeTrue();
        }
    });

    it('writes the starting lists into every store that has none, and nothing on a second run', function () {
        DB::table('b2b.company_types')->delete();
        DB::table('b2b.document_types')->delete();
        DB::table('b2b.store_type_lists')->delete();

        // What the migration does on an installation whose stores already exist.
        $written = app(GiveEveryStoreTheStartingTypes::class)->run();

        expect($written)->toBe(3);

        foreach (b2bTypesStoreIds() as $storeId) {
            expect(b2bTypesCompanyNames($storeId))->toBe(B2B_TYPES_COMPANY_NAMES)
                ->and(b2bTypesDocumentNames($storeId))->toBe(B2B_TYPES_DOCUMENT_NAMES)
                ->and(b2bTypesFlagRow($storeId)['copied_not_reviewed'] ?? null)->toBeTrue();
        }

        $before = array_map(static fn (string $storeId): array => [b2bTypesRows($storeId), b2bTypesFlagRow($storeId)], b2bTypesStoreIds());

        expect(app(GiveEveryStoreTheStartingTypes::class)->run())->toBe(0)
            ->and(array_map(static fn (string $storeId): array => [b2bTypesRows($storeId), b2bTypesFlagRow($storeId)], b2bTypesStoreIds()))->toBe($before);
    });

    it('gives a store opened later the same lists, marked copied, and touches no other store', function () {
        $eg = Fx::storeId('eg');
        $sa = Fx::storeId('sa');
        $saBefore = b2bTypesRows($sa);
        DB::table('b2b.company_types')->where('store_id', $eg)->delete();
        DB::table('b2b.document_types')->where('store_id', $eg)->delete();
        DB::table('b2b.store_type_lists')->where('store_id', $eg)->delete();

        event(new StoreCreated('e1', $eg, CarbonImmutable::now()));

        expect(b2bTypesCompanyNames($eg))->toBe(B2B_TYPES_COMPANY_NAMES)
            ->and(b2bTypesDocumentNames($eg))->toBe(B2B_TYPES_DOCUMENT_NAMES)
            ->and(app(StoreTypeListsRepository::class)->find($eg)?->copiedNotReviewed())->toBeTrue()
            ->and(b2bTypesRows($sa))->toBe($saBefore);
    });

    it('leaves a store with lists, and lists already reviewed, exactly as they are', function () {
        $sa = Fx::storeId('sa');
        // Set to begin with, so "still cleared" below is the writer leaving it, not a default.
        expect(app(StoreTypeListsRepository::class)->find($sa)?->copiedNotReviewed())->toBeTrue();

        b2bTypesMarkReviewed($sa);
        $rows = b2bTypesRows($sa);
        $flag = b2bTypesFlagRow($sa);

        expect(app(GiveEveryStoreTheStartingTypes::class)->run())->toBe(0);
        event(new StoreCreated('e2', $sa, CarbonImmutable::now()));

        expect(b2bTypesRows($sa))->toBe($rows)
            ->and(b2bTypesFlagRow($sa))->toBe($flag)
            ->and(app(StoreTypeListsRepository::class)->find($sa)?->copiedNotReviewed())->toBeFalse();
    });

    it('writes each kind only where that kind is empty, and leaves the flag row that exists', function () {
        $sa = Fx::storeId('sa');
        // Reviewed first, so a writer that set the flag again would show.
        b2bTypesMarkReviewed($sa);
        $companyTypes = b2bTypesRows($sa)['company_types'];
        $flag = b2bTypesFlagRow($sa);
        DB::table('b2b.document_types')->where('store_id', $sa)->delete();

        expect(app(GiveEveryStoreTheStartingTypes::class)->run())->toBe(1)
            ->and(b2bTypesDocumentNames($sa))->toBe(B2B_TYPES_DOCUMENT_NAMES)
            ->and(b2bTypesRows($sa)['company_types'])->toBe($companyTypes)
            ->and(b2bTypesFlagRow($sa))->toBe($flag)
            ->and($flag['copied_not_reviewed'] ?? null)->toBeFalse();
    });

    it('keeps a type staff renamed when the writer runs again', function () {
        $sa = Fx::storeId('sa');
        $types = app(CompanyTypeRepository::class);
        $type = $types->active($sa)[0];
        $type->rename(TypeName::of('مؤسسة', 'Establishment'));
        $types->update($type);

        app(GiveEveryStoreTheStartingTypes::class)->run();

        expect($types->find($type->id())?->name()->en)->toBe('Establishment')
            ->and($types->all($sa))->toHaveCount(6);
    });
});

describe('one list per store (amendment 5)', function () {
    it('keeps each store\'s list its own: a change in one leaves the other as it was', function () {
        $sa = Fx::storeId('sa');
        $eg = Fx::storeId('eg');
        $egBefore = b2bTypesRows($eg);
        $types = app(CompanyTypeRepository::class);
        [$first, $second] = $types->active($sa);
        $first->deactivate(InactiveTypeDisplay::Greyed);
        $types->update($first);
        $second->rename(TypeName::of('شركة ذات مسؤولية محدودة ومختلطة', 'Mixed Limited Liability Company'));
        $types->update($second);

        $ids = static fn (array $list): array => array_map(static fn (CompanyType $type): string => $type->id(), $list);

        expect(b2bTypesRows($eg))->toBe($egBefore)
            ->and($types->active($sa))->toHaveCount(5)
            ->and($types->active($eg))->toHaveCount(6)
            ->and(array_intersect($ids($types->active($sa)), $ids($types->active($eg))))->toBe([])
            ->and(array_intersect($ids($types->all($sa)), $ids($types->all($eg))))->toBe([]);
    });

    it('says a name is taken by another type of that store, in either language, ignoring case — never by another store\'s', function () {
        $sa = Fx::storeId('sa');
        $eg = Fx::storeId('eg');
        $companyTypes = app(CompanyTypeRepository::class);
        $documentTypes = app(DocumentTypeRepository::class);
        $own = $companyTypes->active($sa)[1];
        // A name only 'eg' has.
        $egType = $companyTypes->active($eg)[0];
        $egType->rename(TypeName::of('شركة قابضة', 'Holding Company'));
        $companyTypes->update($egType);

        expect($companyTypes->nameTaken($sa, TypeName::of('اسم جديد', 'LIMITED liability company')))->toBeTrue()
            ->and($companyTypes->nameTaken($sa, TypeName::of('شركة ذات مسؤولية محدودة', 'A new name')))->toBeTrue()
            ->and($companyTypes->nameTaken($eg, TypeName::of('اسم جديد', 'holding COMPANY')))->toBeTrue()
            ->and($companyTypes->nameTaken($sa, TypeName::of('شركة قابضة', 'Holding Company')))->toBeFalse()
            ->and($companyTypes->nameTaken($sa, TypeName::of('اسم جديد', 'A new name')))->toBeFalse()
            // Renaming a type to its own name, or to its own name in other letters, is no clash.
            ->and($companyTypes->nameTaken($sa, TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability COMPANY'), $own->id()))->toBeFalse()
            ->and($documentTypes->nameTaken(strtoupper($sa), TypeName::of('اسم جديد', 'vat CERTIFICATE')))->toBeTrue()
            ->and($documentTypes->nameTaken($sa, TypeName::of('اسم جديد', 'Holding Company')))->toBeFalse();
    });
});

describe('what the database refuses on its own', function () {
    it('refuses a type row the code would never write', function (Closure $insert, string $refusal) {
        expect($insert)->toThrow(QueryException::class, $refusal);
    })->with([
        'no store' => [fn () => b2bTypeRow('company_types', ['store_id' => null]), 'column "store_id"'],
        'a store that does not exist' => [fn () => b2bTypeRow('document_types', ['store_id' => strtolower((string) Str::ulid())]), 'document_types_store_id_foreign'],
        'inactive, with no choice of how it shows' => [fn () => b2bTypeRow('company_types', ['is_active' => false]), 'constraint "company_types_inactive_display_when_inactive"'],
        'active, with a choice of how it shows' => [fn () => b2bTypeRow('document_types', ['inactive_display' => 'HIDDEN']), 'constraint "document_types_inactive_display_when_inactive"'],
        'a way of showing there is none of' => [fn () => b2bTypeRow('company_types', ['is_active' => false, 'inactive_display' => 'FADED']), 'constraint "company_types_inactive_display"'],
        'a blank Arabic name' => [fn () => b2bTypeRow('company_types', ['name_ar' => '  ']), 'company_types_name_ar_present'],
        'a blank English name' => [fn () => b2bTypeRow('document_types', ['name_en' => '']), 'document_types_name_en_present'],
        'a name on two lines' => [fn () => b2bTypeRow('company_types', ['name_en' => "Limited\nPartnership"]), 'company_types_name_en_one_line'],
        'a position below zero' => [fn () => b2bTypeRow('document_types', ['position' => -1]), 'document_types_position_range'],
        'a position past the form' => [fn () => b2bTypeRow('company_types', ['position' => 10001]), 'company_types_position_range'],
        'an English name the store has, in other letters' => [fn () => b2bTypeRow('company_types', ['name_en' => 'limited LIABILITY company']), 'company_types_name_en_unique'],
        'an Arabic name the store has' => [fn () => b2bTypeRow('document_types', ['name_ar' => 'شهادة السجل التجاري']), 'document_types_name_ar_unique'],
    ]);

    it('takes one name in two stores, and in both tables', function () {
        b2bTypeRow('company_types', ['name_en' => 'Holding Company', 'store_id' => Fx::storeId('sa')]);
        b2bTypeRow('company_types', ['name_en' => 'Holding Company', 'store_id' => Fx::storeId('eg')]);
        // A document type is not a company type.
        b2bTypeRow('document_types', ['name_en' => 'Limited Liability Company']);

        expect(DB::table('b2b.company_types')->where('name_en', 'Holding Company')->count())->toBe(2)
            ->and(DB::table('b2b.document_types')->where('name_en', 'Limited Liability Company')->count())->toBe(1);
    });

    it('refuses a second "copied" row for a store, and one for a store that does not exist', function (Closure $insert, string $constraint) {
        expect($insert)->toThrow(QueryException::class, $constraint);
    })->with([
        'a second row' => [fn () => DB::table('b2b.store_type_lists')->insert(['store_id' => Fx::storeId('sa'), 'copied_not_reviewed' => false, 'updated_at' => now()]), 'store_type_lists_pkey'],
        'an unknown store' => [fn () => DB::table('b2b.store_type_lists')->insert(['store_id' => strtolower((string) Str::ulid()), 'copied_not_reviewed' => true, 'updated_at' => now()]), 'store_type_lists_store'],
    ]);
});

describe('the code refuses first: the constraint is dropped, so only the code can hold the rule', function () {
    it('clears how a type showed when it is offered again, without the database\'s help', function () {
        DB::statement('ALTER TABLE b2b.company_types DROP CONSTRAINT company_types_inactive_display_when_inactive');
        $types = app(CompanyTypeRepository::class);
        $type = $types->active(Fx::storeId('sa'))[0];

        $type->deactivate(InactiveTypeDisplay::Greyed);
        $types->update($type);
        expect(DB::table('b2b.company_types')->where('id', $type->id())->value('inactive_display'))->toBe('GREYED');

        $type->activate();
        $types->update($type);

        expect(DB::table('b2b.company_types')->where('id', $type->id())->value('inactive_display'))->toBeNull();
    });

    it('writes one "copied" row per store, without the primary key\'s help', function () {
        DB::statement('ALTER TABLE b2b.store_type_lists DROP CONSTRAINT store_type_lists_pkey');
        $sa = Fx::storeId('sa');
        DB::table('b2b.document_types')->where('store_id', $sa)->delete();

        expect(app(GiveEveryStoreTheStartingTypes::class)->run())->toBe(1)
            ->and(DB::table('b2b.store_type_lists')->where('store_id', $sa)->count())->toBe(1);
    });
});

describe('the repositories', function () {
    it('writes a type with its store, and how it shows while inactive, and reads both back', function () {
        $sa = Fx::storeId('sa');
        $companyTypes = app(CompanyTypeRepository::class);
        $company = CompanyType::add($companyTypes->nextId(), strtoupper($sa), TypeName::of('شركة قابضة', 'Holding Company'), 7);
        $companyTypes->add($company);
        $documentTypes = app(DocumentTypeRepository::class);
        $document = DocumentType::add($documentTypes->nextId(), $sa, TypeName::of('خطاب تفويض', 'Letter of authorisation'), 4, isRequired: false);
        $documentTypes->add($document);

        $read = $companyTypes->find($company->id());

        expect($read?->storeId())->toBe($sa)
            ->and($read?->name()->equals($company->name()))->toBeTrue()
            ->and($read?->position())->toBe(7)
            ->and($read?->isActive())->toBeTrue()
            ->and($read?->inactiveDisplay())->toBeNull();

        $changed = $companyTypes->byId($company->id()) ?? throw new LogicException('missing');
        $changed->deactivate(InactiveTypeDisplay::Greyed);
        $companyTypes->update($changed);
        $changedDocument = $documentTypes->byId($document->id()) ?? throw new LogicException('missing');
        $changedDocument->require();
        $changedDocument->deactivate(InactiveTypeDisplay::Hidden);
        $documentTypes->update($changedDocument);

        $ids = static fn (array $list): array => array_map(static fn (CompanyType|DocumentType $each): string => $each->id(), $list);

        expect($companyTypes->find($company->id())?->inactiveDisplay())->toBe(InactiveTypeDisplay::Greyed)
            ->and($companyTypes->find($company->id())?->isActive())->toBeFalse()
            ->and($documentTypes->find($document->id())?->storeId())->toBe($sa)
            ->and($documentTypes->find($document->id())?->isRequired())->toBeTrue()
            ->and($documentTypes->find($document->id())?->inactiveDisplay())->toBe(InactiveTypeDisplay::Hidden)
            // A deactivated type leaves the form but not the staff's list.
            ->and($ids($documentTypes->active($sa)))->not->toContain($document->id())
            ->and($ids($documentTypes->all($sa)))->toContain($document->id());
    });

    it('orders a store\'s list by position, then by English name', function () {
        $sa = Fx::storeId('sa');
        $types = app(CompanyTypeRepository::class);
        // Two at one position: the name decides between them.
        $types->add(CompanyType::add($types->nextId(), $sa, TypeName::of('ب', 'Bravo Company'), 0));
        $types->add(CompanyType::add($types->nextId(), $sa, TypeName::of('أ', 'Alpha Company'), 0));

        $names = array_map(static fn (CompanyType $type): string => $type->name()->en, $types->all($sa));

        expect(array_slice($names, 0, 3))->toBe(['Alpha Company', 'Bravo Company', 'Sole Proprietorship / Individual Establishment']);
    });

    it('writes a store\'s "copied" row and reads it back, locked or not', function () {
        $sa = Fx::storeId('sa');
        $repository = app(StoreTypeListsRepository::class);
        DB::table('b2b.store_type_lists')->where('store_id', $sa)->delete();

        $repository->add(StoreTypeLists::copied(strtoupper($sa)));

        expect($repository->find($sa)?->storeId())->toBe($sa)
            ->and($repository->find(strtoupper($sa))?->copiedNotReviewed())->toBeTrue();

        DB::transaction(function () use ($repository, $sa) {
            $locked = $repository->byStore($sa) ?? throw new LogicException('missing');
            $locked->markReviewed();
            $repository->update($locked);
        });

        expect($repository->find($sa)?->copiedNotReviewed())->toBeFalse()
            ->and($repository->byStore($sa)?->copiedNotReviewed())->toBeFalse();
    });

    it('finds nothing for an id that is not one, without asking the database', function () {
        DB::enableQueryLog();

        expect(app(CompanyTypeRepository::class)->find('not-an-id'))->toBeNull()
            ->and(app(DocumentTypeRepository::class)->byId("01j8z3k4m5n6p7q8r9s0t1v2w3'--"))->toBeNull()
            ->and(app(CompanyTypeRepository::class)->all('nope'))->toBe([])
            ->and(app(DocumentTypeRepository::class)->active('nope'))->toBe([])
            ->and(app(StoreTypeListsRepository::class)->find('nope'))->toBeNull()
            ->and(app(StoreTypeListsRepository::class)->byStore('nope'))->toBeNull()
            ->and(DB::getQueryLog())->toBe([]);
    });
});
