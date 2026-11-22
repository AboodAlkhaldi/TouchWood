<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Content module raises (content.md §7), each `content.*`, its words in both
 * languages at `content::errors.{snake}`. Other modules see them only as `DomainError`, by their type:
 * modules export no error classes.
 */
abstract class ContentError extends DomainError {}
