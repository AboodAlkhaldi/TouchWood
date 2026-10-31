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
        return ['method' => ['required', 'string', 'in:SMS,AUTHENTICATOR']];
    }
}
