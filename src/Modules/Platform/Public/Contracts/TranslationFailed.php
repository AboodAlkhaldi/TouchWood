<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Enums\TranslationFailure;
use RuntimeException;

/**
 * What `TextTranslator::translate()` throws when the service gave no translation (content.md §2.2).
 *
 * It carries the reason and nothing else: never the service's own text, never the token, and never the
 * service's exception as its previous one (that carries the service's answer), so neither reaches a log
 * or a screen (§1.3). It is not a `DomainError`: the module that asked turns it into its own refusal, in
 * its own words (Content's `TranslationUnavailable`).
 *
 * Kept beside the contract rather than in `Public/Dto`: an exception cannot be a readonly class, which
 * every DTO at a module's boundary is (tests/Architecture/DtoStyleTest).
 */
final class TranslationFailed extends RuntimeException
{
    public function __construct(
        public readonly TranslationFailure $reason,
    ) {
        parent::__construct("The translation failed ({$reason->value}).");
    }
}
