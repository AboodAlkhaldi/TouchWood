<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * A phone number, with its country code.
 */
final class StaffPhoneRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32']];
    }
}
