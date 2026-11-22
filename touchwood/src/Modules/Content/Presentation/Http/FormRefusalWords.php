<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http;

use App\Http\FormErrors;
use Modules\Content\Application\RefusalWords;
use Shared\Domain\Error\DomainError;

/** `RefusalWords` from the framework's form errors: the sentence a form shows for a refusal, in the person's language. */
final readonly class FormRefusalWords implements RefusalWords
{
    public function sentence(DomainError $refusal): string
    {
        return FormErrors::message($refusal);
    }

    public function unexpected(): string
    {
        return (string) __('content::errors.campaign_partly_scheduled.unexpected');
    }
}
