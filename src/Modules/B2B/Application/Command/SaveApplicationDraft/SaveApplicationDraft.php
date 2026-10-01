<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\SaveApplicationDraft;

/**
 * The wizard, saved as it goes (b2b.md §1.2, §3.1).
 *
 * **Only the fields sent change** (amendment 5): a key left out keeps its value, and a key sent
 * empty (null or blank) clears it. The type is one field in two keys — company_type_id for a listed
 * type, company_type_other for the company's own words — so sending either sends the type.
 */
final readonly class SaveApplicationDraft
{
    /**
     * @param  array<string, string|null>  $fields  any of SaveApplicationDraftHandler::FIELDS
     */
    public function __construct(
        public array $fields,
    ) {}
}
