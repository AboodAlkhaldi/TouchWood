<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;

/**
 * One save of the company form (b2b.md §4.5): the fields it carries, each text or empty — the page
 * saves one field as the person leaves it. Shape only (handoff §5.3): whether a value is a valid one
 * is the domain's, answered on its own field.
 */
final class CompanyDraftRequest extends FormRequest
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
        $rules = [];

        foreach (SaveApplicationDraftHandler::FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string'];
        }

        return $rules;
    }

    /**
     * The fields this save carries, and only those: a field left out keeps its value (§3.1).
     *
     * @return array<string, string|null>
     */
    public function fields(): array
    {
        $fields = [];

        foreach (SaveApplicationDraftHandler::FIELDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $fields[$field] = is_string($value) ? $value : null;
            }
        }

        return $fields;
    }
}
