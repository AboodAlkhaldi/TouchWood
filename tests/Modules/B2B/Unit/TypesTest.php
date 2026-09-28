<?php

declare(strict_types=1);

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Model\StoreTypeLists;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\TypeName;

/*
| The company types and document types staff manage, one list of each per store, and each store's
| "copied, not yet reviewed" flag (b2b.md §1.3, amendments 5 and 6(a)).
*/

const TYPES_TEST_STORE = '01j8z3k4m5n6p7q8r9s0t1v2s9';

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
    it('starts active, with no choice of how it shows while inactive', function () {
        $type = CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شركة', 'Company'), 1);

        expect($type->isActive())->toBeTrue()
            ->and($type->inactiveDisplay())->toBeNull();
    });

    it('keeps the store it was added to, in lower case', function () {
        $type = CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', strtoupper(TYPES_TEST_STORE), TypeName::of('شركة', 'Company'), 1);

        expect($type->storeId())->toBe(TYPES_TEST_STORE);
    });

    it('records what changed, and nothing when nothing did', function () {
        $type = CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شركة', 'Company'), 1);

        $type->rename(TypeName::of('شركة', 'Company'));
        $type->moveTo(1);
        $type->activate();

        expect($type->pullChanges())->toBe([]);

        $type->rename(TypeName::of('شركة', 'A company'));
        $type->moveTo(2);
        $type->deactivate(InactiveTypeDisplay::Greyed);

        expect($type->pullChanges())->toBe(['name', 'position', 'is_active', 'inactive_display'])
            ->and($type->isActive())->toBeFalse()
            ->and($type->position())->toBe(2)
            ->and($type->name()->en)->toBe('A company')
            // Pulled once: the next audit entry starts empty.
            ->and($type->pullChanges())->toBe([]);
    });

    it('refuses a position outside the form', function (int $position) {
        expect(fn () => CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شركة', 'Company'), $position))
            ->toThrow(InvalidCompanyAttribute::class);
    })->with([-1, 10001]);
});

describe('a document type', function () {
    it('is asked for only while it is both required and offered', function () {
        $type = DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شهادة', 'Certificate'), 1, isRequired: true);

        expect($type->isAskedFor())->toBeTrue();

        // An inactive type is asked of nobody, whatever its switch says (b2b.md §7).
        $type->deactivate(InactiveTypeDisplay::Hidden);
        expect($type->isAskedFor())->toBeFalse();

        $type->activate();
        $type->makeOptional();
        expect($type->isAskedFor())->toBeFalse()
            ->and($type->pullChanges())->toBe(['is_active', 'inactive_display', 'is_required']);
    });

    it('carries its own "required" switch from the moment it is added', function () {
        expect(DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شهادة', 'Certificate'), 1, isRequired: false)->isRequired())->toBeFalse();
    });

    it('keeps the store it was added to, in lower case', function () {
        $type = DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', strtoupper(TYPES_TEST_STORE), TypeName::of('شهادة', 'Certificate'), 1, isRequired: true);

        expect($type->storeId())->toBe(TYPES_TEST_STORE);
    });
});

describe('deactivating a type: hidden or greyed out (amendment 5)', function () {
    it('keeps the choice until the type is offered again, which clears it', function (InactiveTypeDisplay $shown) {
        $company = CompanyType::add('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شركة', 'Company'), 1);
        $document = DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2w4', TYPES_TEST_STORE, TypeName::of('شهادة', 'Certificate'), 1, isRequired: true);

        $company->deactivate($shown);
        $document->deactivate($shown);

        expect($company->inactiveDisplay())->toBe($shown)
            ->and($document->inactiveDisplay())->toBe($shown);

        $company->activate();
        $document->activate();

        expect($company->isActive())->toBeTrue()
            ->and($company->inactiveDisplay())->toBeNull()
            ->and($document->isActive())->toBeTrue()
            ->and($document->inactiveDisplay())->toBeNull();
    })->with([InactiveTypeDisplay::Hidden, InactiveTypeDisplay::Greyed]);

    it('records being offered again for the audit log: that it is active, and how it showed', function () {
        // Read back inactive, so nothing a deactivation recorded can stand in for what activating does.
        $company = CompanyType::reconstitute('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شركة', 'Company'), 1, false, InactiveTypeDisplay::Greyed);
        $document = DocumentType::reconstitute('01j8z3k4m5n6p7q8r9s0t1v2w4', TYPES_TEST_STORE, TypeName::of('شهادة', 'Certificate'), 1, false, InactiveTypeDisplay::Hidden, true);

        $company->activate();
        $document->activate();

        expect($company->pullChanges())->toBe(['is_active', 'inactive_display'])
            ->and($document->pullChanges())->toBe(['is_active', 'inactive_display']);
    });

    it('changes only how an inactive type shows when staff choose again, and nothing for the same choice', function () {
        $company = CompanyType::reconstitute('01j8z3k4m5n6p7q8r9s0t1v2w3', TYPES_TEST_STORE, TypeName::of('شركة', 'Company'), 1, false, InactiveTypeDisplay::Hidden);
        $document = DocumentType::reconstitute('01j8z3k4m5n6p7q8r9s0t1v2w4', TYPES_TEST_STORE, TypeName::of('شهادة', 'Certificate'), 1, false, InactiveTypeDisplay::Hidden, true);

        $company->deactivate(InactiveTypeDisplay::Hidden);
        $document->deactivate(InactiveTypeDisplay::Hidden);

        expect($company->pullChanges())->toBe([])
            ->and($document->pullChanges())->toBe([]);

        $company->deactivate(InactiveTypeDisplay::Greyed);
        $document->deactivate(InactiveTypeDisplay::Greyed);

        expect($company->pullChanges())->toBe(['inactive_display'])
            ->and($company->inactiveDisplay())->toBe(InactiveTypeDisplay::Greyed)
            ->and($document->pullChanges())->toBe(['inactive_display'])
            ->and($document->inactiveDisplay())->toBe(InactiveTypeDisplay::Greyed);
    });
});

describe('a store\'s lists, copied and not yet reviewed (amendment 6(a))', function () {
    it('starts copied, for the store in lower case', function () {
        $lists = StoreTypeLists::copied(strtoupper(TYPES_TEST_STORE));

        expect($lists->storeId())->toBe(TYPES_TEST_STORE)
            ->and($lists->copiedNotReviewed())->toBeTrue();
    });

    it('is reviewed once, and records it once', function () {
        $lists = StoreTypeLists::copied(TYPES_TEST_STORE);

        $lists->markReviewed();

        expect($lists->copiedNotReviewed())->toBeFalse()
            ->and($lists->pullChanges())->toBe(['copied_not_reviewed']);

        $lists->markReviewed();

        expect($lists->copiedNotReviewed())->toBeFalse()
            ->and($lists->pullChanges())->toBe([]);
    });

    it('records nothing for lists already reviewed', function () {
        $lists = StoreTypeLists::reconstitute(TYPES_TEST_STORE, false);

        $lists->markReviewed();

        expect($lists->pullChanges())->toBe([])
            ->and($lists->copiedNotReviewed())->toBeFalse();
    });
});
