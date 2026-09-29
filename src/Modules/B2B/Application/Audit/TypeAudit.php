<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Audit;

use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\TypeName;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What staff's changes to a store's two lists leave in the audit log (b2b.md §1.3, §3.2, amendment
 * 10). A type's names are the store's words, not anybody's personal data, so everything is recorded
 * by value. Each entry sits on the type, in the type's own store.
 */
final class TypeAudit
{
    public static function added(CompanyType|DocumentType $type): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('name_ar', null, $type->name()->ar)
            ->changed('name_en', null, $type->name()->en)
            ->changed('position', null, $type->position());

        if ($type instanceof DocumentType) {
            $changes->changed('is_required', null, $type->isRequired());
        }

        return self::entry('added', $type, $changes);
    }

    public static function renamed(CompanyType|DocumentType $type, TypeName $from): AuditEntryDto
    {
        $changes = AuditChanges::none();

        if ($from->ar !== $type->name()->ar) {
            $changes->changed('name_ar', $from->ar, $type->name()->ar);
        }

        if ($from->en !== $type->name()->en) {
            $changes->changed('name_en', $from->en, $type->name()->en);
        }

        return self::entry('renamed', $type, $changes);
    }

    public static function moved(CompanyType|DocumentType $type, int $from): AuditEntryDto
    {
        return self::entry('moved', $type, AuditChanges::none()->changed('position', $from, $type->position()));
    }

    public static function requirement(DocumentType $type): AuditEntryDto
    {
        return self::entry('requirement_changed', $type, AuditChanges::none()->changed('is_required', ! $type->isRequired(), $type->isRequired()));
    }

    /**
     * @param  string|null  $replacedBy  a company type replaced on the companies holding it (§1.3)
     */
    public static function deactivated(CompanyType|DocumentType $type, ?string $replacedBy = null): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('is_active', true, false)
            ->changed('inactive_display', null, $type->inactiveDisplay()?->value);

        if ($type instanceof CompanyType) {
            $changes->changed('replaced_by', null, $replacedBy);
        }

        return self::entry('deactivated', $type, $changes);
    }

    public static function activated(CompanyType|DocumentType $type, InactiveTypeDisplay $was): AuditEntryDto
    {
        return self::entry('activated', $type, AuditChanges::none()
            ->changed('is_active', false, true)
            ->changed('inactive_display', $was->value, null));
    }

    /**
     * The store's admins looked at both lists and found nothing to change (§1.3, amendment 10(d)).
     */
    public static function listsReviewed(string $storeId): AuditEntryDto
    {
        return new AuditEntryDto('b2b.type_lists.reviewed', 'b2b.type_lists', $storeId, $storeId, AuditChanges::none()
            ->changed('copied_not_reviewed', true, false));
    }

    private static function entry(string $what, CompanyType|DocumentType $type, AuditChanges $changes): AuditEntryDto
    {
        $subject = $type instanceof CompanyType ? 'b2b.company_type' : 'b2b.document_type';

        return new AuditEntryDto("{$subject}.{$what}", $subject, $type->id(), $type->storeId(), $changes);
    }
}
