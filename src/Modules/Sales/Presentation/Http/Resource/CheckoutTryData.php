<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Sales\Application\Checkout\CheckoutTryView;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the page shows of a try (sales.md §9.8). `mainStoreUrl` only while the store is switched off: the closed
 * store's one page, shown when `firstSeen` (payments.md §1.10).
 */
#[TypeScript]
final class CheckoutTryData extends Data
{
    public function __construct(
        public string $tryId,
        public string $state,
        public string $payment,
        public ?string $orderNumber,
        public ?string $windowEndsAt,
        public ?string $waitEndsAt,
        public ?string $holdEndsAt,
        public bool $nothingCharged,
        public bool $refundDue,
        public ?string $declineCode,
        public bool $firstSeen,
        public string $serverNow,
        public ?string $mainStoreUrl = null,
    ) {}

    public static function of(CheckoutTryView $view, ?string $mainStoreUrl = null): self
    {
        return new self(
            $view->tryId,
            $view->state,
            $view->payment->value,
            $view->orderNumber,
            self::moment($view->windowEndsAt),
            self::moment($view->waitEndsAt),
            self::moment($view->holdEndsAt),
            $view->nothingCharged,
            $view->refundDue,
            $view->declineCode,
            $view->firstSeen,
            now()->format(DateTimeInterface::ATOM),
            $mainStoreUrl,
        );
    }

    private static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format(DateTimeInterface::ATOM);
    }
}
