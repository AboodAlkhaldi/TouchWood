<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * Moving every company of one active company type to another (b2b.md §1.3, amendment 11(c)): the type
 * they go to. Whether it is an active type of the same store is the use case's.
 */
final class StaffTransferTypeRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['target' => ['nullable', 'string']];
    }
}
