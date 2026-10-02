<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * Why staff suspend or reinstate a company (b2b.md §3.2): required, at most 1000 characters, line
 * breaks allowed — the domain's rule (`Remark`), which refuses an empty one in the panel's language.
 */
final class StaffReasonRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string']];
    }
}
