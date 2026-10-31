<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The critical-SMS switch (amendment 81), sent the moment it is flipped, as the topic switches are.
 */
final class CriticalSmsRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'critical_sms' => ['required', 'boolean'],
        ];
    }
}
