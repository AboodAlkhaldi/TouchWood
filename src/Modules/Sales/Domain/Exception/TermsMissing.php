<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The store has no published Terms and Conditions, so it takes no orders (flags-2026-11.md S3.3): placing and paying
 * online are refused until its terms are published.
 */
final class TermsMissing extends SalesError
{
    public function __construct()
    {
        parent::__construct('The store has no published terms and takes no orders.');
    }

    public function type(): string
    {
        return 'sales.terms_missing';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
