<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A customer the message composer offers (ops.md §1.8, §4): one of the chosen store the reader may view and write to. */
#[TypeScript]
final class CustomerToMessageData extends Data
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public string $homeStoreCode,
    ) {}
}
