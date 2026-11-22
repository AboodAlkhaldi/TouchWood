<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\StaffCompanyActions;

/**
 * What the staff member acting now may do to one company, **next** (b2b.md §4.6, amendment 21): each
 * flag is true only for someone holding the job in the company's home store **and** only when the
 * company's state lets it happen — an application waiting, the company suspended or not.
 *
 * Offering is never allowing: every handler behind these asks again (handoff §19).
 */
final readonly class StaffCompanyActions
{
    /** Approving is offered but refused while the company is still "Other" (amendment 13(b)). */
    public const string APPROVE_TYPE_NOT_SET = 'type_not_set';

    /** Approving is offered but refused once the account is erased (amendment 13(e)). */
    public const string APPROVE_ACCOUNT_DELETED = 'account_deleted';

    /**
     * @param  list<CorrectionChoice>  $typeChoices  the home store's company types a correction may
     *                                               pick; empty unless $mayCorrectType
     */
    public function __construct(
        public bool $mayOpenDocuments,
        public bool $mayApprove,
        /** Why Approve, offered, would be refused now; null when it would not. */
        public ?string $approveRefusal,
        public bool $mayReject,
        public bool $maySuspend,
        public bool $mayReinstate,
        public bool $mayCorrectType,
        /** "Other" may be chosen: never for an approved company (amendment 13(b)). */
        public bool $mayChooseOther,
        public array $typeChoices,
    ) {}

    public static function none(): self
    {
        return new self(false, false, null, false, false, false, false, false, []);
    }
}
