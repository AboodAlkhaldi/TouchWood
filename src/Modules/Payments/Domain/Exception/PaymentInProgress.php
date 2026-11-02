<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A charge while another attempt of the same try or request is open (payments.md §1.1 rule 3, §7).
 */
final class PaymentInProgress extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('A payment is already in progress.');
    }

    public function type(): string
    {
        return 'payments.payment_in_progress';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
