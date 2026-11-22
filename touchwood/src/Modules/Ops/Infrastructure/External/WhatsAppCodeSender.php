<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\CodePurpose;
use Modules\Ops\Application\Messages\CodeSender;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\WhatsAppAlert;
use Modules\Ops\Domain\Exception\CodesUnavailable;

/**
 * Codes and the two critical alerts through Meta's WhatsApp Cloud API (ops.md §9.8 points 1, 9; D1, D2).
 *
 * **A code** is Meta's authentication template, approved once under `WHATSAPP_CODE_TEMPLATE`, in the recipient's
 * language (`ar` or `en`, one approved translation each). Meta fixes its words ("*123456* is your verification code",
 * and the do-not-share line when the template asks for it), so only the code goes: **twice**, as the body's parameter
 * and as the Copy Code button's. The purpose changes nothing here: the staff sign-in code loses the old warning line
 * (W4, accepted). UNVERIFIED (see WhatsAppClient): the payload's button part.
 *
 * **An alert** is a utility template of our own fixed words, with no parameter, under its own `.env` name.
 *
 * Nothing set is no site down (W3): with a setting missing, or Meta refusing the setup, a code is `CodesUnavailable`;
 * an alert is a `ProviderFailed` that waits for a person, which the queue's tries then give up on.
 */
final readonly class WhatsAppCodeSender implements CodeSender
{
    public function __construct(
        private WhatsAppClient $client,
        private WhatsAppSettings $settings,
    ) {}

    public function sendCode(#[\SensitiveParameter] string $phone, string $locale, CodePurpose $purpose, #[\SensitiveParameter] string $code): string
    {
        if ($this->settings->missingForCodes() !== []) {
            throw new CodesUnavailable;
        }

        try {
            return $this->client->sendTemplate($phone, $this->settings->codeTemplate(), self::language($locale), [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
            ]);
        } catch (ProviderFailed $failed) {
            // The setup refused, for everybody: the person is told codes cannot go, never what Meta said.
            throw $failed->kind === FailureKind::WaitsForPerson ? new CodesUnavailable($failed) : $failed;
        }
    }

    public function sendAlert(#[\SensitiveParameter] string $phone, string $locale, WhatsAppAlert $alert, #[\SensitiveParameter] string $text): string
    {
        $which = $alert === WhatsAppAlert::KeyExpiring ? 'expiring' : 'refused';
        $missing = $this->settings->missingForAlert($which);

        if ($missing !== []) {
            throw new ProviderFailed('WhatsApp: '.implode(', ', $missing).' is not set, so the alert cannot go.', FailureKind::WaitsForPerson);
        }

        return $this->client->sendTemplate($phone, $this->settings->alertTemplate($which), self::language($locale));
    }

    /** The approved translations are `ar` and `en`. */
    private static function language(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }
}
