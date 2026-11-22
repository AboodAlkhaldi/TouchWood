<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * The kinds of policy a store publishes (flags-2026-11.md S3.1, the owner's list, Q1), as other modules name them
 * when they ask `ShopPolicies`: Sales for the terms a checkout ticks (S3.3), Access for sign-up's links (S3.4), B2B for
 * a company application's terms (S3.6). Content keeps the policies themselves; its own kind has the same values.
 * The cases are in the spec's order, the order the shop's footer lists them in (S3.5, F19).
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
}
