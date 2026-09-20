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
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|string',
            'password' => 'required|string',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'account_type' => 'required|string',
            'locale' => 'required|string',
            'terms' => 'accepted',
        ];
    }
}
