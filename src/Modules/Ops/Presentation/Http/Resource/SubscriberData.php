<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One email on a store's newsletter list (ops.md §1.9, §4): a guest (GUEST) or a customer who agreed
 * (CUSTOMER), the language, whether it is confirmed, and when it signed up and was confirmed (ISO 8601,
 * UTC). Only a guest may be removed by staff.
 */
#[TypeScript]
final class SubscriberData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public string $locale,
        public string $kind,
        public bool $confirmed,
        public string $createdAt,
        public ?string $confirmedAt,
    ) {}
}
