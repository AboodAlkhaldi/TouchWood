<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * Adding, renaming or moving a type (b2b.md §1.3): its two names, its position, and for a document
 * type whether it is required. The names' and the position's rules are the domain's (`TypeName`,
 * `TypePosition`).
 */
final class StaffTypeRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['nullable', 'string'],
            'name_en' => ['nullable', 'string'],
            'position' => ['nullable'],
            'required' => ['nullable', 'boolean'],
        ];
    }
}
