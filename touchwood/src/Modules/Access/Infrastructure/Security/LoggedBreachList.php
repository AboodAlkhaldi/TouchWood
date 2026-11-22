<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use Exception;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Illuminate\Validation\NotPwnedVerifier;
use Psr\Log\LoggerInterface;

/**
 * Laravel's leaked-password check, which logs every outage (amendment 20). Laravel's own reports
 * a failed connection but quietly treats an error reply (a 503, a 429) as "not found"; this one
 * logs that too. Either way the password is accepted, so an outage never blocks anyone. Only the
 * first 5 characters of the password's SHA-1 hash leave the server.
 */
final class LoggedBreachList extends NotPwnedVerifier
{
    public function __construct(
        HttpFactory $factory,
        private readonly LoggerInterface $log,
    ) {
        parent::__construct($factory);
    }

    /**
     * @param  string  $hashPrefix
     * @return Collection<int, non-falsy-string> lines "HASH-SUFFIX:COUNT"
     */
    protected function search($hashPrefix)
    {
        try {
            $response = $this->factory->withHeaders(['Add-Padding' => true])
                ->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);
        } catch (Exception $e) {
            report($e);

            return new Collection;
        }

        if (! $response->successful()) {
            $this->log->warning('The leaked-password service answered with an error; the password was accepted unchecked.', ['status' => $response->status()]);

            return new Collection;
        }

        return (new Stringable($response->body()))->trim()->explode("\n")
            ->filter(fn (string $line): bool => str_contains($line, ':'));
    }
}
