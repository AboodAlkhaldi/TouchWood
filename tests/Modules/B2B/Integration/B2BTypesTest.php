<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\TypeName;

/*
| The two tables staff manage (b2b.md §1.3, §5): what ships in them, what the database refuses,
| and the repositories that read and write them.
*/

uses(RefreshDatabase::class);

/**
 * A type row written past the code, to show what the database refuses on its own.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 *
 * @param  array<string, mixed>  $values
 */
function b2bTypeRow(string $table, array $values = []): void
{
    DB::table("b2b.{$table}")->insert([
        'id' => strtolower((string) Str::ulid()),
        'name_ar' => 'نوع '.Str::random(6),
        'name_en' => 'Type '.Str::random(6),
        'position' => 50,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
        ...$values,
    ]);
}

describe('what ships', function () {
    it('puts the b2b schema on the search path, so a fresh migrate wipes it', function () {
        expect(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('b2b');
    });

    it('ships the six company types the owner listed, in that order, all active', function () {
        $types = app(CompanyTypeRepository::class)->active();

        expect(array_map(static fn (CompanyType $type): array => [$type->name()->ar, $type->name()->en], $types))->toBe([
            ['مؤسسة فردية', 'Sole Proprietorship / Individual Establishment'],
            ['شركة ذات مسؤولية محدودة', 'Limited Liability Company'],
            ['شركة مساهمة', 'Joint Stock Company'],
            ['شركة مساهمة مبسطة', 'Simplified Joint Stock Company'],
            ['شركة تضامن', 'General Partnership'],
            ['شركة توصية بسيطة', 'Limited Partnership'],
        ]);
    });

    it('ships the three known document types, required', function () {
        $types = app(DocumentTypeRepository::class)->active();

        expect(array_map(static fn (DocumentType $type): array => [$type->name()->ar, $type->name()->en, $type->isRequired()], $types))->toBe([
            ['شهادة ضريبة القيمة المضافة', 'VAT certificate', true],
            ['شهادة السجل التجاري', 'Commercial registration certificate', true],
            ['هوية المفوّض بالتوقيع', 'Authorised signatory ID', true],
        ]);
    });
});

describe('what the database refuses on its own', function () {
    it('refuses a type row the code would never write', function (Closure $insert, string $constraint) {
        expect($insert)->toThrow(QueryException::class, $constraint);
    })->with([
        'a blank Arabic name' => [fn () => b2bTypeRow('company_types', ['name_ar' => '  ']), 'company_types_name_ar_present'],
        'a blank English name' => [fn () => b2bTypeRow('document_types', ['name_en' => '']), 'document_types_name_en_present'],
        'a name on two lines' => [fn () => b2bTypeRow('company_types', ['name_en' => "Limited\nPartnership"]), 'company_types_name_en_one_line'],
        'a position below zero' => [fn () => b2bTypeRow('document_types', ['position' => -1]), 'document_types_position_range'],
        'a position past the form' => [fn () => b2bTypeRow('company_types', ['position' => 10001]), 'company_types_position_range'],
        'an English name taken, in other letters' => [fn () => b2bTypeRow('company_types', ['name_en' => 'limited LIABILITY company']), 'company_types_name_en_unique'],
        'an Arabic name taken' => [fn () => b2bTypeRow('document_types', ['name_ar' => 'شهادة السجل التجاري']), 'document_types_name_ar_unique'],
    ]);

    it('lets the two tables share a name: a document type is not a company type', function () {
        b2bTypeRow('document_types', ['name_en' => 'Limited Liability Company']);

        expect(DB::table('b2b.document_types')->where('name_en', 'Limited Liability Company')->count())->toBe(1);
    });
});

describe('the repositories', function () {
    it('writes a company type and reads it back as it was', function () {
        $types = app(CompanyTypeRepository::class);
        $type = CompanyType::add($types->nextId(), TypeName::of('شركة قابضة', 'Holding Company'), 7);
        $types->add($type);

        $read = $types->find($type->id());

        expect($read?->name()->equals($type->name()))->toBeTrue()
            ->and($read?->position())->toBe(7)
            ->and($read?->isActive())->toBeTrue();
    });

    it('saves a change, and a deactivated type leaves the form but not the list', function () {
        $types = app(DocumentTypeRepository::class);
        $type = DocumentType::add($types->nextId(), TypeName::of('خطاب تفويض', 'Letter of authorisation'), 4, isRequired: false);
        $types->add($type);

        $changed = $types->byId($type->id());
        $changed?->require();
        $changed?->deactivate();
        $types->update($changed ?? $type);

        $ids = static fn (array $list): array => array_map(static fn (DocumentType $each): string => $each->id(), $list);

        expect($types->find($type->id())?->isRequired())->toBeTrue()
            ->and($ids($types->active()))->not->toContain($type->id())
            ->and($ids($types->all()))->toContain($type->id());
    });

    it('orders by position, then by English name', function () {
        $types = app(CompanyTypeRepository::class);
        // Two at one position: the name decides between them.
        $types->add(CompanyType::add($types->nextId(), TypeName::of('ب', 'Bravo Company'), 0));
        $types->add(CompanyType::add($types->nextId(), TypeName::of('أ', 'Alpha Company'), 0));

        $names = array_map(static fn (CompanyType $type): string => $type->name()->en, $types->all());

        expect(array_slice($names, 0, 3))->toBe(['Alpha Company', 'Bravo Company', 'Sole Proprietorship / Individual Establishment']);
    });

    it('says a name is taken in either language, ignoring case, except by the type itself', function () {
        $types = app(CompanyTypeRepository::class);
        $own = $types->active()[1];

        expect($types->nameTaken(TypeName::of('اسم جديد', 'LIMITED liability company')))->toBeTrue()
            ->and($types->nameTaken(TypeName::of('شركة ذات مسؤولية محدودة', 'A new name')))->toBeTrue()
            ->and($types->nameTaken(TypeName::of('اسم جديد', 'A new name')))->toBeFalse()
            // Renaming a type to its own name, or to its own name in other letters, is no clash.
            ->and($types->nameTaken(TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability COMPANY'), $own->id()))->toBeFalse();
    });

    it('finds nothing for an id that is not one, without asking the database', function () {
        DB::enableQueryLog();

        expect(app(CompanyTypeRepository::class)->find('not-an-id'))->toBeNull()
            ->and(app(DocumentTypeRepository::class)->byId("01j8z3k4m5n6p7q8r9s0t1v2w3'--"))->toBeNull()
            ->and(DB::getQueryLog())->toBe([]);
    });
});
