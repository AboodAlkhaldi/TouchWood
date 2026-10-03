<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * Approving a company (b2b.md §3.2): an optional note, emailed to the customer with the approval
 * (amendment 1).
 */
final class StaffApproveRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string']];
    }
}
