<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

/**
 * A message written out in one language (ops.md §1.1): the bell's title and line, the email's subject and
 * lines, its one button and the page it opens - a path inside the site - and, for an order's own emails
 * (C1, C6b), the order's lines and totals. C4 has a second button when the order holds a tracking link: the
 * carrier's own page, a full https:// address (Sales refuses any other). Email only: the bell stays inside the site.
 */
final readonly class MessageText
{
    /**
     * @param  list<string>  $lines
     * @param  list<array{label: string, value: string}>  $summary  the order's lines, then its totals
     */
    public function __construct(
        public string $title,
        public string $line,
        public string $subject,
        public array $lines,
        public ?string $link,
        public ?string $action = null,
        public array $summary = [],
        public ?string $sms = null,
        public ?string $trackLink = null,
        public ?string $trackAction = null,
    ) {}
}
