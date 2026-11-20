<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;

/**
 * The terms an order was placed under (flags-2026-11.md S3.3, F16): the store's published Terms and Conditions - the
 * policy and the version of its text the customer ticked - and when; with the Returns and Refunds policy and its
 * version when the store had one published, as the ticked sentence names it too (the builder's pick). Kept on a
 * payment's try from Pay and handed to its order, so an online order keeps what was ticked when Pay was pressed.
 *
 * The policy's ids are Content's, kept as plain values: Sales never reads Content's tables (owner, 2026-11-20).
 */
final readonly class AcceptedTerms
{
    public string $policyId;

    public ?string $returnsPolicyId;

    /** @throws InvalidSalesAttribute */
    public function __construct(
        string $policyId,
        public int $version,
        public DateTimeImmutable $acceptedAt,
        ?string $returnsPolicyId = null,
        public ?int $returnsVersion = null,
    ) {
        $this->policyId = self::id('terms_policy', $policyId);
        $this->returnsPolicyId = $returnsPolicyId === null ? null : self::id('returns_policy', $returnsPolicyId);

        if ($version < 1) {
            throw new InvalidSalesAttribute('terms_version', 'a policy version is 1 or more');
        }

        // The Returns policy's id and version go together, or neither.
        if (($this->returnsPolicyId === null) !== ($returnsVersion === null)) {
            throw new InvalidSalesAttribute('returns_policy', 'the policy and its version together');
        }

        if ($returnsVersion !== null && $returnsVersion < 1) {
            throw new InvalidSalesAttribute('returns_version', 'a policy version is 1 or more');
        }
    }

    /** @throws InvalidSalesAttribute */
    private static function id(string $attribute, string $id): string
    {
        $id = strtolower(trim($id));

        if (preg_match('/\A[0-9a-z]{26}\z/', $id) !== 1) {
            throw new InvalidSalesAttribute($attribute, 'a policy id');
        }

        return $id;
    }
}
