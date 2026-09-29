<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The company's address, changed on its own and saved at once (b2b.md §4.5, UpdateCompanyContact).
 * Shape only: the domain decides what an address may hold.
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
            'address' => ['required', 'string'],
        ];
    }

    public function address(): string
    {
        $value = $this->input('address');

        return is_string($value) ? $value : '';
    }
}
