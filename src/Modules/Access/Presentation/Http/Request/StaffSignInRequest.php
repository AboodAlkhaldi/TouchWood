<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The email and password.
 */
final class StaffSignInRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:1024']];
    }
}
