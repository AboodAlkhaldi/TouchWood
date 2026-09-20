<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The current password and the new one.
 */
final class OwnPasswordRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:1024'], 'password' => ['required', 'string', 'max:1024']];
    }
}
