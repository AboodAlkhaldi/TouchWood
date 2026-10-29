<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A customer the message composer offers (ops.md §1.8, §4): the email only when the reader may view that customer. */
#[TypeScript]
final class CustomerToMessageData extends Data
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public ?string $email,
    ) {}
}
