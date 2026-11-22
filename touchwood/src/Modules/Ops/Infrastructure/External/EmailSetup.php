<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Contracts\Config\Repository;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * Whether email is set up on a production server (ops.md §7; owner, 2026-11-22: **email not set up never stops the
 * site**). Email is set up when `OPS_EMAIL_DRIVER` is `brevo`, the Brevo key is one that can work, and the sender is one
 * of ours. Anything else is a problem, named by its setting and never by a value (a key may be among them): the boot
 * guard logs them loudly, the binding gives a sender that sends nothing (NotSetUpEmailSender), and the Super Admins see
 * a red notice over the panel (EmailBanner). Outside production none of this applies: `log` writes to the log as ever.
 */
final class EmailSetup
{
    /**
     * Everything wrong with the email settings, by the setting's name.
     *
     * @return array<string, string>
     */
    public static function problems(Repository $config): array
    {
        $problems = [];
        $email = $config->get('ops.email.driver');
        $from = $config->get('mail.from.address');

        if ($email !== 'brevo') {
            $problems['OPS_EMAIL_DRIVER'] = 'OPS_EMAIL_DRIVER is "'.(is_string($email) ? $email : '').'": no email would be sent.';
        }

        try {
            $brevoKey = $config->get('services.brevo.key');
            BrevoAnswers::key(is_string($brevoKey) ? $brevoKey : '');
        } catch (ProviderFailed $failed) {
            $problems['BREVO_API_KEY'] = $failed->getMessage();
        }

        // Laravel's own default stands for "never set": Brevo would refuse every email from it.
        if (! is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false || $from === 'hello@example.com') {
            $problems['MAIL_FROM_ADDRESS'] = 'MAIL_FROM_ADDRESS is not set to a sender of ours: every email would be refused.';
        }

        return $problems;
    }
}
