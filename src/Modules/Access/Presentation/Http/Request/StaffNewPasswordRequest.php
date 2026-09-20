<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * A new password; its rules are the domain's (spec §1.8).
 */
final class StaffNewPasswordRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:1024']];
    }
}
