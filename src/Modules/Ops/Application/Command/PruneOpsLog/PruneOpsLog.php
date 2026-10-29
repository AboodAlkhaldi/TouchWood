<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\PruneOpsLog;

/**
 * The nightly removal (ops.md §5): inbox entries, sent messages and handled events older than twelve
 * months go.
 */
final readonly class PruneOpsLog {}
