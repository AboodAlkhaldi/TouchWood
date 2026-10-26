<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A payment or a refund recorded on an order (§1.9), a voided one with its reason. */
#[TypeScript]
final class MoneyRecordData extends Data
{
    public function __construct(
        public string $id,
        public string $kind,
        public MoneyData $amount,
        public string $way,
        public ?string $reference,
        public ?string $note,
        public string $happenedOn,
        public ?string $recordedBy,
        public string $recordedAt,
        public ?string $voidedBy,
        public ?string $voidedAt,
        public ?string $voidReason,
    ) {}
}
