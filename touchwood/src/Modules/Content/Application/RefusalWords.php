<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Shared\Domain\Error\DomainError;

/**
 * Another refusal's words in the person's language, for a refusal that carries one (`CampaignPartlyScheduled`): the
 * sentence a form would show for it. Answered by Content's Presentation layer, which alone may use the framework's
 * form-error glue (AppHttpTest).
 */
interface RefusalWords
{
    /** The refusal's own sentence, as a form shows it (its rule's words, else its detail). */
    public function sentence(DomainError $refusal): string;

    /** The words for a failure that is no refusal - the database away, its retries spent. */
    public function unexpected(): string;
}
