<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The number a customer is adding or changing to (spec §1.3). Whether it is a real number, and
 * whether somebody else already has it, is the domain's to answer.
 */
final class CustomerPhoneRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32']];
    }
}
