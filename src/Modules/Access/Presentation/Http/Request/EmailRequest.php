<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * An email address.
 */
final class EmailRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'max:254']];
    }
}
