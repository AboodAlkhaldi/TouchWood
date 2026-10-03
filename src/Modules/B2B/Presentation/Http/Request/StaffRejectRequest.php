<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Request;

/**
 * Rejecting an application (b2b.md §1.2, §3.2, amendment 4): a reason, and optionally the items it
 * marks — fields by name, papers by their document type — and what it asks this company for, each a
 * text or a file under a label staff write.
 */
final class StaffRejectRequest extends StaffFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string'],
            'flags' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
            'requests' => ['nullable', 'array'],
        ];
    }

    /**
     * Each request as the use case takes it; a malformed entry is kept with empty words, which the
     * domain refuses on the request it belongs to.
     *
     * @return list<array{kind: string, label: string}>
     */
    public function requested(): array
    {
        $requests = $this->input('requests');
        $made = [];

        foreach (is_array($requests) ? $requests : [] as $request) {
            $made[] = [
                'kind' => is_array($request) && is_string($request['kind'] ?? null) ? $request['kind'] : '',
                'label' => is_array($request) && is_string($request['label'] ?? null) ? $request['label'] : '',
            ];
        }

        return $made;
    }
}
