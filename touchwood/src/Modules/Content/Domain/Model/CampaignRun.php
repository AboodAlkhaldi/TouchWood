<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;
use Modules\Content\Domain\ValueObject\PriceChoices;

/**
 * One store's part of a campaign (content.md §1.7 #2): its own id - the one Ops' events and `campaignForMessage`
 * carry (#13) - its store's moments, its state, a draft's price choices (#14), its notice (#10) and the stamps of
 * its two ending alerts (#11).
 */
final readonly class CampaignRun
{
    public function __construct(
        public string $id,
        public string $campaignId,
        public string $storeId,
        public RunState $state,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?PriceChoices $priceChoices,
        public ?string $noticeId,
        public ?DateTimeImmutable $wentLiveAt = null,
        public ?DateTimeImmutable $endedAt = null,
        public ?DateTimeImmutable $cancelledAt = null,
        public ?DateTimeImmutable $warned72At = null,
        public ?DateTimeImmutable $warned24At = null,
        // Its shop page's address per language (H7), given once at Schedule (CampaignRules::giveAddress) and never
        // written through the run: read only.
        public ?string $slugAr = null,
        public ?string $slugEn = null,
    ) {}

    /** The same run with these fields changed. */
    public function with(
        ?RunState $state = null,
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
        bool $choices = false,
        ?PriceChoices $priceChoices = null,
        bool $notice = false,
        ?string $noticeId = null,
        bool $warnings = false,
        ?DateTimeImmutable $warned72At = null,
        ?DateTimeImmutable $warned24At = null,
        ?DateTimeImmutable $endedAt = null,
        ?DateTimeImmutable $cancelledAt = null,
    ): self {
        return new self(
            $this->id,
            $this->campaignId,
            $this->storeId,
            $state ?? $this->state,
            $startsAt ?? $this->startsAt,
            $endsAt ?? $this->endsAt,
            $choices ? $priceChoices : $this->priceChoices,
            $notice ? $noticeId : $this->noticeId,
            $this->wentLiveAt,
            $endedAt ?? $this->endedAt,
            $cancelledAt ?? $this->cancelledAt,
            $warnings ? $warned72At : $this->warned72At,
            $warnings ? $warned24At : $this->warned24At,
            $this->slugAr,
            $this->slugEn,
        );
    }

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them (choices as a count)
     */
    public function fields(): array
    {
        return [
            'state' => $this->state->value,
            'starts_at' => $this->startsAt->format(DATE_ATOM),
            'ends_at' => $this->endsAt->format(DATE_ATOM),
            'notice_id' => $this->noticeId,
            'price_choices' => $this->priceChoices === null ? null : count($this->priceChoices->targets),
        ];
    }
}
