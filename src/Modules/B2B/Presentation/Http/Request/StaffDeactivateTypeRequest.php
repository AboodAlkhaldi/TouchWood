<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * Deactivating a type (b2b.md §1.3, amendments 5 and 11(b)): how it shows to new applications, and
 * for a company type its holders — left with it, moved to another active type, or moved to a new
 * type made in the same step.
 *
 * The two choices come from the screen's own radio buttons, so a value outside them is a request
 * nobody's screen sends, refused here by shape.
 */
final class StaffDeactivateTypeRequest extends StaffFormRequest
{
    public const string LEAVE = 'leave';

    public const string REPLACE = 'replace';

    public const string NEW = 'new';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'shown' => ['required', 'in:HIDDEN,GREYED'],
            'holders' => ['nullable', 'in:'.self::LEAVE.','.self::REPLACE.','.self::NEW],
            'replacement' => ['nullable', 'string'],
            'new_name_ar' => ['nullable', 'string'],
            'new_name_en' => ['nullable', 'string'],
            'new_position' => ['nullable'],
        ];
    }

    public function holders(): string
    {
        $holders = $this->input('holders');

        return is_string($holders) && $holders !== '' ? $holders : self::LEAVE;
    }
}
