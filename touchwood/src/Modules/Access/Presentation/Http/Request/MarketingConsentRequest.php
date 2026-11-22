<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * The "offers and news" switch in the customer's account (amendment 79).
 */
final class MarketingConsentRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'marketing_consent' => ['required', 'boolean'],
        ];
    }
}
