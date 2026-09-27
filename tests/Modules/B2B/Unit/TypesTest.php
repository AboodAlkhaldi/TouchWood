<?php

declare(strict_types=1);

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\TypeName;

/*
| The company types and document types staff manage (b2b.md §1.3).
*/

describe('a type\'s name', function () {
    it('takes both languages, trimmed', function () {
        $name = TypeName::of("  شركة تضامن\n", ' General Partnership ');

        expect($name->ar)->toBe('شركة تضامن')
            ->and($name->en)->toBe('General Partnership');
    });

    it('takes a name of exactly the limit', function () {
        expect(TypeName::of(str_repeat('ا', TypeName::MAX), str_repeat('a', TypeName::MAX))->en)->toHaveLength(TypeName::MAX);
    });

    it('refuses what a dropdown cannot show', function (string $ar, string $en, string $attribute, string $reason) {
        $error = null;

        try {
            TypeName::of($ar, $en);
        } catch (InvalidCompanyAttribute $caught) {
            $error = $caught;
        }

        // Nothing thrown leaves both null, which fails here too.
        expect($error?->attribute)->toBe($attribute)
            ->and($error?->reason)->toBe($reason);
    })->with([
        'no Arabic name' => ['   ', 'Limited Partnership', 'name_ar', 'required'],
        'no English name' => ['شركة', '', 'name_en', 'required'],
        'a line break inside' => ['شركة', "Limited\nPartnership", 'name_en', 'on one line, without control characters'],
        'a control character' => ["شركة\u{0007}", 'Limited Partnership', 'name_ar', 'on one line, without control characters'],
        'bytes that are not text' => ['شركة', "Limited \xC3\x28", 'name_en', 'text'],
        'one character too long' => [str_repeat('ا', TypeName::MAX + 1), 'Limited Partnership', 'name_ar', 'at most 100 characters'],
    ]);
});

describe('a company type', function () {
    it('starts active', function () {
        expect(CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TypeName::of('شركة', 'Company'), 1)->isActive())->toBeTrue();
    });

    it('records what changed, and nothing when nothing did', function () {
        $type = CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TypeName::of('شركة', 'Company'), 1);

        $type->rename(TypeName::of('شركة', 'Company'));
        $type->moveTo(1);
        $type->activate();

        expect($type->pullChanges())->toBe([]);

        $type->rename(TypeName::of('شركة', 'A company'));
        $type->moveTo(2);
        $type->deactivate();

        expect($type->pullChanges())->toBe(['name', 'position', 'is_active'])
            ->and($type->isActive())->toBeFalse()
            ->and($type->position())->toBe(2)
            ->and($type->name()->en)->toBe('A company')
            // Pulled once: the next audit entry starts empty.
            ->and($type->pullChanges())->toBe([]);
    });

    it('refuses a position outside the form', function (int $position) {
        expect(fn () => CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TypeName::of('شركة', 'Company'), $position))
            ->toThrow(InvalidCompanyAttribute::class);
    })->with([-1, 10001]);
});

describe('a document type', function () {
    it('is asked for only while it is both required and offered', function () {
        $type = DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TypeName::of('شهادة', 'Certificate'), 1, isRequired: true);

        expect($type->isAskedFor())->toBeTrue();

        // An inactive type is asked of nobody, whatever its switch says (b2b.md §7).
        $type->deactivate();
        expect($type->isAskedFor())->toBeFalse();

        $type->activate();
        $type->makeOptional();
        expect($type->isAskedFor())->toBeFalse()
            ->and($type->pullChanges())->toBe(['is_active', 'is_required']);
    });

    it('carries its own "required" switch from the moment it is added', function () {
        expect(DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TypeName::of('شهادة', 'Certificate'), 1, isRequired: false)->isRequired())->toBeFalse();
    });
});
