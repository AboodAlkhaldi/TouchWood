<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * A staff member's own sign-in method, while both are ticked (amendment 91).
 */
final class SignInMethodRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', 'in:SMS,AUTHENTICATOR'],
            // Asked for SMS (owner, 2026-10-31); whether it is right is the handler's to say.
            'current_password' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
