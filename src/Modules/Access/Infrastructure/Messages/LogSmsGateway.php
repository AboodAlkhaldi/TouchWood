<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use Modules\Access\Application\Messages\SmsGateway;
use Psr\Log\LoggerInterface;

/**
 * For development and tests, until the SMS provider is chosen: the message, code included, goes to
 * the application log. Never the driver of a live system.
 */
final readonly class LogSmsGateway implements SmsGateway
{
    public function __construct(
        private LoggerInterface $log,
    ) {}

    public function send(string $phone, string $message): void
    {
        $this->log->info('SMS (log driver)', ['to' => $phone, 'message' => $message]);
    }
}
