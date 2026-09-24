<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * Why a staff member did something to somebody's account (spec §3.3).
 *
 * Asked for on every one of those actions, because each is a thing somebody will later ask about,
 * and the audit entry is only as useful as the sentence written here. How long a reason may be is
 * the domain's; this only says one must be given.
 */
final class ReasonRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
