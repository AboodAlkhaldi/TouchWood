<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Feedback module raises (feedback.md §7).
 */
abstract class FeedbackError extends DomainError {}
