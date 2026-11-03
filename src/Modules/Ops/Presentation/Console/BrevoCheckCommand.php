<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Infrastructure\External\BrevoAnswers;

/**
 * The real token's check (ops.md §7), run by hand at staging and at any new key: three reads and no write, so
 * nothing reaches a customer. Exits non-zero when the key or the sender would fail. Never prints the key.
 *
 * What Brevo's reference offers, read 2026-11-03: GET /v3/account, whose 200 "confirms your key is valid"
 * (https://developers.brevo.com/docs/quickstart); GET /v3/senders, each with `email` and `active`
 * (https://developers.brevo.com/reference/get-senders); GET /v3/senders/domains, each with `domain_name`,
 * `authenticated` and `verified` (https://developers.brevo.com/reference/get-domains). The reference notes an
 * active sender "must still be verified by email" and offers no read of that, so the address's own emailed code
 * cannot be checked from here: the first real email is what proves it.
 */
final class BrevoCheckCommand extends Command
{
    public const string NAME = 'ops:brevo:check';

    private const string BASE = 'https://api.brevo.com/v3';

    protected $signature = self::NAME;

    protected $description = 'Read Brevo\'s account and senders with the key set (BREVO_API_KEY); sends nothing';

    public function handle(Factory $http, Config $config): int
    {
        try {
            $key = BrevoAnswers::key((string) $config->get('services.brevo.key'));
        } catch (ProviderFailed $failed) {
            $this->error($failed->getMessage());

            return self::FAILURE;
        }

        $get = fn (string $path): Response => $http->withHeaders(['api-key' => $key])->acceptJson()
            ->connectTimeout(BrevoAnswers::CONNECT_SECONDS)->timeout(BrevoAnswers::SECONDS)->get(self::BASE.$path);

        try {
            return $this->check($get, (string) $config->get('mail.from.address'));
        } catch (ConnectionException) {
            $this->error('Brevo: the API could not be reached.');

            return self::FAILURE;
        }
    }

    /**
     * @param  callable(string): Response  $get
     *
     * @throws ConnectionException
     */
    private function check(callable $get, string $from): int
    {
        $account = $get('/account');

        if (! $account->ok() || ! is_array($account->json())) {
            $this->error(BrevoAnswers::refused('the key', $account)->getMessage());

            if ($account->status() === 401) {
                $this->line('Look at BREVO_API_KEY (an API key, not an SMTP key), and at this server\'s address under Brevo\'s Settings > Security > Authorized IPs.');
            }

            return self::FAILURE;
        }

        $this->info('The key works.');
        $this->plans($account);

        $from = strtolower(trim($from));
        $senders = $get('/senders');

        if (! $senders->ok() || ! is_array($senders->json('senders'))) {
            $this->error(BrevoAnswers::refused('the list of senders', $senders)->getMessage());

            return self::FAILURE;
        }

        $sender = collect($senders->json('senders'))->first(static fn (mixed $one): bool => is_array($one) && is_string($one['email'] ?? null) && strtolower($one['email']) === $from);

        if (! is_array($sender)) {
            $this->error("The sender {$from} (MAIL_FROM_ADDRESS) is not among Brevo's senders: every email would be refused.");

            return self::FAILURE;
        }

        if (($sender['active'] ?? false) !== true) {
            $this->error("The sender {$from} (MAIL_FROM_ADDRESS) is at Brevo but not active: every email would be refused.");

            return self::FAILURE;
        }

        $this->info("The sender {$from} is active at Brevo.");
        $this->domain($get('/senders/domains'), substr((string) strrchr($from, '@'), 1));
        $this->line('Nothing was sent. Brevo offers no read of whether the address itself passed its emailed code: the first real email proves that.');

        return self::SUCCESS;
    }

    private function plans(Response $account): void
    {
        foreach ((array) $account->json('plan') as $plan) {
            if (is_array($plan) && is_string($plan['type'] ?? null)) {
                $this->line("Plan {$plan['type']}: ".(is_numeric($plan['credits'] ?? null) ? $plan['credits'] : 'unknown').' credits left.');
            }
        }
    }

    /** A warning, not a failure: Brevo's reference does not say a domain not authenticated stops an email. */
    private function domain(Response $domains, string $name): void
    {
        $domain = collect((array) $domains->json('domains'))->first(static fn (mixed $one): bool => is_array($one) && is_string($one['domain_name'] ?? null) && strtolower($one['domain_name']) === $name);

        if (! $domains->ok() || ! is_array($domain)) {
            $this->warn("The domain {$name} is not listed among Brevo's domains: mail from it may land in spam, or be refused.");
        } elseif (($domain['authenticated'] ?? false) !== true || ($domain['verified'] ?? false) !== true) {
            $this->warn("The domain {$name} is at Brevo but not authenticated and verified: mail from it may land in spam, or be refused.");
        } else {
            $this->info("The domain {$name} is authenticated and verified.");
        }
    }
}
