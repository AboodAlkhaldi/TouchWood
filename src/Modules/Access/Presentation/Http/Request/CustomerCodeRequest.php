<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The code that went to the number a customer entered (spec §1.3).
 */
final class CustomerCodeRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:16']];
    }
}
