<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\FeedbackActions;

/** Which of the Support screens' buttons the person looking may be offered in a store (feedback.md §4.1). */
final readonly class FeedbackActions
{
    public function __construct(
        public bool $mayModerate,
        public bool $mayAnswer,
        public bool $mayViewWishlists,
        public bool $mayEditSettings,
        /** The ready reasons are one list for every store: edited only with the settings job in every store (§4.2 B4). */
        public bool $mayEditReasons = false,
    ) {}
}
