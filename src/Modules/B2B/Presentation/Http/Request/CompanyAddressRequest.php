<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The company's address, changed on its own and saved at once (b2b.md §4.5, UpdateCompanyContact).
 * Shape only: the domain decides what an address may hold — an empty one included, so the refusal
 * reads in the page's language (the project has no translated validation messages; the review of
 * step 6).
 */
final class CompanyAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'address' => ['nullable', 'string'],
        ];
    }

    public function address(): string
    {
        $value = $this->input('address');

        return is_string($value) ? $value : '';
    }
}
