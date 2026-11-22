<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What every staff form of B2B's screens shares (b2b.md §4.6): **shape only**. Who may send it is
 * the handler's question, never the request's (tests/Architecture/AccessDecisionsTest), and whether
 * a value is acceptable is the domain's — an empty reason included — so a refusal reads in the
 * panel's language: the project has no translated validation messages (the review of step 6).
 */
abstract class StaffFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A posted text, or empty when none came: the domain says what an empty one means. */
    public function text(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : '';
    }

    /** A posted text, or null when none came or it is only spaces. */
    public function optionalText(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * A posted whole number, or -1 when none came or it is not one — which the domain refuses as out
     * of range, in the panel's language (TypePosition).
     */
    public function number(string $key): int
    {
        $value = $this->input($key);

        return is_numeric($value) && (string) (int) $value === trim((string) $value) ? (int) $value : -1;
    }

    /**
     * @return list<string>
     */
    public function texts(string $key): array
    {
        $values = $this->input($key);

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
