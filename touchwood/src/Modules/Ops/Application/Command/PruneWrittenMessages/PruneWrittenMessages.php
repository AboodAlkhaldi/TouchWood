<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\PruneWrittenMessages;

/**
 * The nightly removal of step 7's rows (ops.md §5): staff messages, and notices sent or cancelled,
 * older than twelve months; guests who never confirmed their sign-up, after seven days.
 */
final readonly class PruneWrittenMessages {}
