<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Types;

use Modules\B2B\Domain\ValueObject\TypeName;

/**
 * The lists every store starts with (b2b.md §1.3, amendments 2, 5 and 6(a)): six company types and
 * three document types, in the owner's words and order. They are one country's legal forms and
 * papers, copied into every store for now; each store's admins change their own store's lists, and
 * until they do, the types page says the lists were copied (StoreTypeLists).
 *
 * "Other" is not among the company types: it is not a row, and the company describes it in its own
 * words. The three document types are required, as they have been since the owner named them
 * (2026-09-19; the Arabic names 2026-09-27).
 */
final class StartingTypes
{
    /**
     * @return list<TypeName>
     */
    public static function companyTypes(): array
    {
        return [
            TypeName::of('مؤسسة فردية', 'Sole Proprietorship / Individual Establishment'),
            TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability Company'),
            TypeName::of('شركة مساهمة', 'Joint Stock Company'),
            TypeName::of('شركة مساهمة مبسطة', 'Simplified Joint Stock Company'),
            TypeName::of('شركة تضامن', 'General Partnership'),
            TypeName::of('شركة توصية بسيطة', 'Limited Partnership'),
        ];
    }

    /**
     * Every one required.
     *
     * @return list<TypeName>
     */
    public static function documentTypes(): array
    {
        return [
            TypeName::of('شهادة ضريبة القيمة المضافة', 'VAT certificate'),
            TypeName::of('شهادة السجل التجاري', 'Commercial registration certificate'),
            TypeName::of('هوية المفوّض بالتوقيع', 'Authorised signatory ID'),
        ];
    }
}
