<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One customer in the staff list (frontend.md §3.7, G1).
 *
 * The design's order count and lifetime spend are not here: they come with Sales, in stage 6. A
 * column that would only ever read zero is worse than a column that is not there.
 */
#[TypeScript]
final class CustomerRow extends Data
{
    /**
     * @param  string  $status  ACTIVE or BLOCKED, as Access names it
     * @param  string|null  $deletionScheduledFor  YYYY-MM-DD when one is pending; null otherwise.
     *                                             It is a third thing the list says about an
     *                                             account, beside active and blocked
     * @param  string  $homeStore  the store they registered in, named in the panel's language
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public string $accountType,
        public string $status,
        public bool $emailVerified,
        public bool $phoneVerified,
        public ?string $deletionScheduledFor,
        public bool $anonymized,
        public string $homeStore,
        public string $registeredAt,
    ) {}
}
