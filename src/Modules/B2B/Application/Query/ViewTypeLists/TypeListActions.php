<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewTypeLists;

/**
 * What the reader may do to the list shown, in its store (b2b.md §3.2, §4.6) — each job asked on
 * its own, since a role holds exactly the jobs given to it (amendment 10). Offering is never
 * allowing: every handler asks again.
 */
final readonly class TypeListActions
{
    public function __construct(
        /** The tabs: a job on that list in this store. */
        public bool $mayReadCompanyTypes,
        public bool $mayReadDocumentTypes,
        public bool $mayAdd,
        /** Rename, move; for a document type also required or optional. */
        public bool $mayUpdate,
        /** Deactivate, and activate again. */
        public bool $mayDeactivate,
        /** Company types: deactivating into a new type also needs the job of adding (amendment 11(b)). */
        public bool $mayDeactivateIntoNew,
        /** Company types: move every holder of one active type to another (amendment 11(c)). */
        public bool $mayTransfer,
        /** Either list's update job clears the "copied" notice (amendment 10(d)). */
        public bool $mayMarkReviewed,
    ) {}
}
