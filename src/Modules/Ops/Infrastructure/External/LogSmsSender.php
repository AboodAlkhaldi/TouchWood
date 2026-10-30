<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\SmsSender;
use Psr\Log\LoggerInterface;

/**
 * For development and tests, until the owner names the SMS provider (ops.md §2.2): the message goes
 * to the application log. Never the driver of a live system - the binding refuses it in production.
 */
final readonly class LogSmsSender implements SmsSender
{
    public function __construct(
        private LoggerInterface $log,
    ) {}

    public function send(string $phone, #[\SensitiveParameter] string $message): ?string
    {
        $this->log->info('SMS (log driver)', ['to' => $phone, 'message' => $message]);

        return null;
    }
}
