<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * A staff correction of a company's type (b2b.md §3.2): a listed type of its home store, or "Other"
 * in words — exactly one, which the use case checks — and, for a deactivated type, the confirmation
 * that it becomes active again (amendment 8(b)).
 */
final class StaffCorrectTypeRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'type_id' => ['nullable', 'string'],
            'other' => ['nullable', 'string'],
            'confirm_reactivation' => ['nullable', 'boolean'],
        ];
    }
}
