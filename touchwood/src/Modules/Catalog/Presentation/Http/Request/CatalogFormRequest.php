<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Catalog\Application\Import\DescriptionText;

/**
 * What every form of Catalog's screens shares (catalog.md §4.4): **shape only**, as B2B's staff forms.
 * Who may send it is the handler's question, never the request's (tests/Architecture/
 * AccessDecisionsTest), and whether a value is acceptable is the domain's — an empty name included —
 * so a refusal reads in the panel's language, beside its field.
 */
final class CatalogFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
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
     * of range, in the panel's language. Arabic digits were turned into 0-9 as they were typed.
     */
    public function number(string $key): int
    {
        $value = $this->input($key);

        return is_numeric($value) && (string) (int) $value === trim((string) $value) ? (int) $value : -1;
    }

    /** As number(), but null when nothing came — a field left empty on purpose. */
    public function optionalNumber(string $key): ?int
    {
        return $this->optionalText($key) === null && ! is_int($this->input($key)) ? null : $this->number($key);
    }

    /**
     * Posted texts, in their order — anything that is not text is left out.
     *
     * @return list<string>
     */
    public function texts(string $key): array
    {
        $values = $this->input($key);

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /**
     * A description or a warranty's terms, typed with the products file's marks (catalog.md §1.12,
     * P1): a blank line a paragraph, `- ` a list item, `# ` a heading, `**…**` bold. Nothing typed is
     * none; the domain checks what was made of it (StructuredText).
     *
     * @return array<string, mixed>|null
     */
    public function marks(string $key): ?array
    {
        $text = $this->optionalText($key);

        return $text === null ? null : DescriptionText::document($text);
    }
}
