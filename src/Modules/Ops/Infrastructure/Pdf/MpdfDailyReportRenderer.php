<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Pdf;

use Illuminate\Contracts\View\Factory;
use Modules\Ops\Application\DailyReport\DailyReport;
use Modules\Ops\Application\DailyReport\DailyReportRenderer;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The daily report's PDF through mPDF (the owner's choice, 2026-10-29): the view `ops::daily-report` in
 * the reader's language, Arabic right to left.
 *
 * The font is the site's own, IBM Plex Sans Arabic (owner, 2026-10-30): the build downloads it only as
 * web fonts (vite.config.ts), which mPDF cannot read, so its TrueType files sit in resources/fonts with
 * their licence (IBM's release @ibm/plex-sans-arabic@1.1.0). It draws Arabic - the comma "،" among it -
 * and Latin letters and digits, so no second font is needed.
 */
final readonly class MpdfDailyReportRenderer implements DailyReportRenderer
{
    /** The name the view's CSS uses for the font. */
    public const string FONT = 'ibmplexsansarabic';

    public function __construct(private Factory $views) {}

    public function render(DailyReport $report, string $locale): string
    {
        $locale = $locale === 'en' ? 'en' : 'ar';
        $html = $this->views->make('ops::daily-report', [
            'report' => $report,
            'locale' => $locale,
            'rtl' => $locale === 'ar',
            'f' => new ReportFormat($locale),
            'logo' => public_path('icons/email-logo.png'),
        ])->render();

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'fontDir' => [resource_path('fonts/ibm-plex-sans-arabic')],
            'fontdata' => [
                self::FONT => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    // Its own shaping tables: joined Arabic letters, lam-alef, kashida to justify.
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => self::FONT,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 16,
            'margin_footer' => 7,
            'tempDir' => storage_path('framework/mpdf'),
            // A section's heading and first block sit in one table that never splits (the view's
            // `keep`): one too long for what is left of a page moves whole to the next, never shrunk.
            'shrink_tables_to_fit' => 0,
        ]);
        $pdf->SetDirectionality($locale === 'ar' ? 'rtl' : 'ltr');
        $pdf->SetTitle(trans('ops::daily_report.title', [], $locale));
        $pdf->SetHTMLFooter($this->views->make('ops::daily-report-footer', ['locale' => $locale])->render());
        $pdf->WriteHTML($html);

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
