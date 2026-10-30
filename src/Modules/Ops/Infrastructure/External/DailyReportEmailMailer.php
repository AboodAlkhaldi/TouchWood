<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Ops\Application\DailyReport\DailyReportMailer;
use Modules\Ops\Application\Messages\EmailAttachment;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The daily report's email (ops.md §1.11): a few lines in the person's language, the PDF attached, from
 * the application's sender address (`mail.from`), through Ops' email adapter.
 */
final readonly class DailyReportEmailMailer implements DailyReportMailer
{
    public function __construct(
        private EmailSender $sender,
        private Factory $views,
        private Translator $words,
        private Repository $config,
    ) {}

    public function send(StaffDto $to, DailyReportFile $report, ?StoreDto $store, string $pdf): void
    {
        $locale = $report->locale;
        $name = $store === null
            ? (string) $this->words->get('ops::daily_report.all_stores', [], $locale)
            : $store->name->in($locale);
        $replace = ['name' => $to->firstName, 'store' => $name, 'day' => $report->day];
        /** @var list<string> $lines */
        $lines = array_map(
            fn (string $line): string => (string) $this->words->get($line, $replace, $locale),
            ['ops::daily_report.email.hello', 'ops::daily_report.email.attached', 'ops::daily_report.email.kept'],
        );
        $subject = (string) $this->words->get('ops::daily_report.email.subject', $replace, $locale);

        $this->sender->send(new OutgoingEmail(
            fromEmail: (string) $this->config->get('mail.from.address'),
            fromName: (string) $this->config->get('mail.from.name'),
            toEmail: $to->email,
            toName: trim($to->firstName.' '.$to->lastName),
            subject: $subject,
            html: $this->views->make('ops::daily-report-email', ['lines' => $lines, 'rtl' => $locale === 'ar', 'locale' => $locale])->render(),
            text: implode("\n\n", $lines),
            attachments: [new EmailAttachment('daily-report-'.($store === null ? 'all' : $store->code).'-'.$report->day.'.pdf', $pdf)],
        ));
    }
}
