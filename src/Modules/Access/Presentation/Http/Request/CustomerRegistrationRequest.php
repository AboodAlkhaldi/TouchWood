<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The registration form (spec §1.2). The handler checks the values themselves; this only says which
 * fields must be there at all.
 */
final class CustomerRegistrationRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', 'string', 'max:16'],
            'locale' => ['required', 'string', 'max:2'],
            'terms' => 'accepted',
        ];
    }
}
