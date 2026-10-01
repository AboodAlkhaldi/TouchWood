<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The company's address, changed on its own and saved at once (b2b.md §4.5, UpdateCompanyContact):
 * which of the account's saved addresses is picked (amendment 16(f)). Shape only: whether it is one
 * the company may pick is the use case's — an empty pick included, so the refusal reads in the
 * page's language (the project has no translated validation messages; the review of step 6).
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
            'address_id' => ['nullable', 'string'],
        ];
    }

    public function addressId(): string
    {
        $value = $this->input('address_id');

        return is_string($value) ? $value : '';
    }
}
