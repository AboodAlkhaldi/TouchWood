<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The new number, and the current password that proves it is really this person asking
 * (frontend.md §3.2, B2).
 *
 * The password is asked for because the number is where the sign-in code goes: a stolen session
 * must not be able to move the second factor on its own (owner, 2026-09-21).
 */
final class OwnPhoneChangeRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'current_password' => ['required', 'string', 'max:1024'],
        ];
    }
}
