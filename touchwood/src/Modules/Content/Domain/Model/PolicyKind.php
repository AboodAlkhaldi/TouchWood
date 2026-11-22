<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * The fixed list of policy kinds (flags-2026-11.md S3.1, Q1: the owner kept it), so the shop knows which one is the
 * terms. **A store has at most one published policy of each kind, Other excepted** (`isUnique`). Named in both
 * languages at `content::policies.kinds.{key}`.
 */
enum PolicyKind: string
{
    case Terms = 'TERMS';
    case Privacy = 'PRIVACY';
    case Returns = 'RETURNS';
    case Shipping = 'SHIPPING';
    case Warranty = 'WARRANTY';
    case PaymentMethods = 'PAYMENT_METHODS';
    case Complaints = 'COMPLAINTS';
    case CompanyTerms = 'COMPANY_TERMS';
    case Other = 'OTHER';

    /** The words' name for it. */
    public function key(): string
    {
        return strtolower($this->value);
    }

    /** Whether a store may have only one published policy of this kind: every kind but Other. */
    public function isUnique(): bool
    {
        return $this !== self::Other;
    }
}
