<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

use Shared\Domain\Text\LatinDigits;

/**
 * A code from the authenticator app, or a recovery code instead (amendment 91). Digits typed on an
 * Arabic keyboard are the same digits (amendment 63).
 */
final class AppCodeRequest extends AccessFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => LatinDigits::of($this->string('code')->toString())]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'recovery' => ['sometimes', 'boolean'],
            'trust_browser' => ['sometimes', 'boolean'],
        ];
    }
}
