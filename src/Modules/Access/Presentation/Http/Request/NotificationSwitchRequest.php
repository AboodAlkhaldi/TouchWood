<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * One topic's two switches, sent the moment one of them is flipped (frontend.md §3.2, B4).
 *
 * One topic per request, not the whole tab: the decision was that each switch saves as it is
 * flipped, so a request carries exactly the change the person just made. Whether the topic is a
 * topic at all is Access's to say — the handler refuses an unknown one with its own error.
 */
final class NotificationSwitchRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'topic' => ['required', 'string', 'max:32'],
            'email' => ['required', 'boolean'],
            'panel' => ['required', 'boolean'],
        ];
    }
}
