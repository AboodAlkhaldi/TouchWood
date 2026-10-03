<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The account holder — the company's responsible person (b2b.md §1.1), read from Access.
 */
#[TypeScript]
final class StaffHolderData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone,
        public bool $emailVerified,
        public bool $phoneVerified,
        /** The account was erased: its name and email are placeholders (§1.1, amendment 13(e)). */
        public bool $anonymized,
    ) {}
}
