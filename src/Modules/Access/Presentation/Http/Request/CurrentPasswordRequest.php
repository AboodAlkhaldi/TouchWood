<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The password somebody already has, asked for to confirm something irreversible - closing an
 * account (spec §1.10). Only the one field: nothing new is being set.
 */
final class CurrentPasswordRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:1024']];
    }
}
