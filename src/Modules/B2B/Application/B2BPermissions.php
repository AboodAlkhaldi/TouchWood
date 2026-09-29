<?php

declare(strict_types=1);

namespace Modules\B2B\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions B2B's use cases check (b2b.md §3). Declared into Access's catalog at boot.
 *
 * The company's own side (§3.1) is two automatic permissions every customer holds for their own
 * data; the handlers themselves refuse an individual account, since an account type never changes.
 *
 * The staff side (§3.2) is **one permission per job** (amendments 10 and 11): twelve, named as every
 * module's are, an action sharing one with its undo — as blocking and unblocking a customer share
 * `access.customer.block`. Every one is per store — the account's home store for a company, the
 * list's own store for a type — none is admin-only, and all sit in the Companies group.
 */
final class B2BPermissions
{
    /** Start, save and send an application, with its files and answers; read the company (§3.1). */
    public const string APPLY = 'b2b.company.apply';

    /** Change the company's address — in every status but suspended (§3.1, amendment 9(d)). */
    public const string UPDATE = 'b2b.company.update';

    /** List companies and view one. */
    public const string COMPANY_VIEW = 'b2b.company.view';

    /** Open a company's papers and file answers — each opening audited. */
    public const string COMPANY_DOCUMENT_VIEW = 'b2b.company_document.view';

    /** Approve or reject a sent application. */
    public const string COMPANY_REVIEW = 'b2b.company.review';

    /** Suspend a company, and reinstate it. */
    public const string COMPANY_SUSPEND = 'b2b.company.suspend';

    /** Correct a company's type. Reactivating a type on the way also needs COMPANY_TYPE_DEACTIVATE. */
    public const string COMPANY_CORRECT_TYPE = 'b2b.company.correct_type';

    /** Move every company of one active type to another — a job of its own (amendment 11(c)). */
    public const string COMPANY_TRANSFER_TYPE = 'b2b.company.transfer_type';

    /** Add a company type to a store's list. */
    public const string COMPANY_TYPE_CREATE = 'b2b.company_type.create';

    /** Rename or reorder a company type; mark the store's lists reviewed. */
    public const string COMPANY_TYPE_UPDATE = 'b2b.company_type.update';

    /** Deactivate a company type — leaving or replacing it on its companies — and activate it again. */
    public const string COMPANY_TYPE_DEACTIVATE = 'b2b.company_type.deactivate';

    /** Add a document type to a store's list. */
    public const string DOCUMENT_TYPE_CREATE = 'b2b.document_type.create';

    /** Rename or reorder a document type, make it required or optional; mark the lists reviewed. */
    public const string DOCUMENT_TYPE_UPDATE = 'b2b.document_type.update';

    /** Deactivate a document type, and activate it again. */
    public const string DOCUMENT_TYPE_DEACTIVATE = 'b2b.document_type.deactivate';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        // Store-free: a company is valid in every store (§1.1), and its account is the only
        // "scope" either permission has.
        $own = [
            new PermissionDefinitionDto(self::APPLY, PermissionAudience::EveryCustomer, kind: PermissionKind::Global),
            new PermissionDefinitionDto(self::UPDATE, PermissionAudience::EveryCustomer, kind: PermissionKind::Global),
        ];

        $staff = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Companies),
            self::staff(),
        );

        return [...$own, ...$staff];
    }

    /**
     * @return list<string> the twelve staff jobs (§3.2, amendments 10 and 11(c))
     */
    public static function staff(): array
    {
        return [
            self::COMPANY_VIEW,
            self::COMPANY_DOCUMENT_VIEW,
            self::COMPANY_REVIEW,
            self::COMPANY_SUSPEND,
            self::COMPANY_CORRECT_TYPE,
            self::COMPANY_TRANSFER_TYPE,
            self::COMPANY_TYPE_CREATE,
            self::COMPANY_TYPE_UPDATE,
            self::COMPANY_TYPE_DEACTIVATE,
            self::DOCUMENT_TYPE_CREATE,
            self::DOCUMENT_TYPE_UPDATE,
            self::DOCUMENT_TYPE_DEACTIVATE,
        ];
    }
}
