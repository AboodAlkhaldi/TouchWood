<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Provider;

use RuntimeException;

/**
 * Thrown from the HTTP client's header and progress callbacks to stop reading an answer past
 * `OdooAdapter::MOST_ANSWER_BYTES` (sync.md §2.4): never shown, the adapter turns it into its reason.
 */
final class OdooAnswerTooLarge extends RuntimeException {}
