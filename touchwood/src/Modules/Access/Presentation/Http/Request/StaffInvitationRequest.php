<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The invitee's password and phone.
 */
final class StaffInvitationRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:1024'], 'phone' => ['required', 'string', 'max:32']];
    }
}
