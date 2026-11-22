<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * What a customer may change about themselves: their name and the language we write to them in
 * (spec §3.1). The email is not here because it never changes, and the phone goes through a code.
 */
final class CustomerProfileRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'locale' => ['required', 'string', 'max:5'],
        ];
    }
}
