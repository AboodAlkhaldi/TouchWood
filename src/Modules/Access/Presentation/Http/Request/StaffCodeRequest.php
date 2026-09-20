<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * An SMS code, and whether to trust this browser.
 */
final class StaffCodeRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:16'], 'trust_browser' => ['sometimes', 'boolean']];
    }
}
