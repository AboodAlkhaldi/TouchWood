<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

use Shared\Domain\Text\LatinDigits;

/**
 * An SMS code, and whether to trust this browser.
 */
final class StaffCodeRequest extends AccessFormRequest
{
    /**
     * A code typed on an Arabic keyboard is the same code (access.md amendment 63): the boxes turn its
     * digits already, and so does this, for a code that arrives any other way.
     */
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
        return ['code' => ['required', 'string', 'max:16'], 'trust_browser' => ['sometimes', 'boolean']];
    }
}
