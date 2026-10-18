import { usePage } from '@inertiajs/react';
import { Note } from '@/components/Note';
import { formatDate, Time } from '@/components/Time';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { formatMoney, type Money } from '@/lib/money';
import { useTranslator } from '@/lib/t';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types/page';
import type { ExpiringData, HistoryRowData, LotData } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';

/*
| The parts Loyalty's screens share (loyalty.md §4.1) - the panel's, the customer page's section and
| the shop's: the programme-off note, points and money written as every screen writes them,
| "expiring next", a history and the live lots. Not a page: each counts inside the pages that import it.
*/

/** The page's language, for numbers and dates in Latin digits (frontend.md §1.8). */
export function useLocale(): string {
    return usePage<SharedProps>().props.locale;
}

/** Points, grouped in Latin digits ("12,500"). */
export function Points({ value, signed = false, className }: { value: number; signed?: boolean; className?: string }) {
    const locale = useLocale();
    const text = figure(locale, Math.abs(value));

    return (
        <bdi dir="ltr" className={cn('tw-figure', className)}>
            {signed ? `${value < 0 ? '−' : '+'}${text}` : value < 0 ? `−${text}` : text}
        </bdi>
    );
}

/** An amount with its currency (lib/money). */
export function Amount({ value, className }: { value: Money; className?: string }) {
    const locale = useLocale();

    return (
        <bdi dir="ltr" className={cn('tw-figure', className)}>
            {formatMoney(value, locale)}
        </bdi>
    );
}

/**
 * A day, YYYY-MM-DD already in the store's zone, written as the page writes dates - isolated, since it
 * always goes inside a sentence, where an Arabic one would otherwise reorder its parts (frontend.md §1.8).
 */
export function useDay(): (day: string) => string {
    const locale = useLocale();

    return (day: string) => isolate(formatDate(`${day}T12:00:00Z`, 'UTC', locale));
}

/** A value that is not there: Geist's em dash. */
export function Unknown() {
    return <span className="text-ink-subtle">—</span>;
}

/** Above every staff screen of a store whose programme is off (answer 9). */
export function ProgrammeOffNote({ on }: { on: boolean }) {
    const t = useTranslator();

    return on ? null : (
        <Note variant="warning" data-test="programme-off">
            {t('loyalty::admin_points.programme_off')}
        </Note>
    );
}

/** "1,200 on 3 Jan 2027", or an em dash. */
export function ExpiringCell({ expiring }: { expiring: ExpiringData | null }) {
    const t = useTranslator();
    const day = useDay();
    const locale = useLocale();

    return expiring === null ? <Unknown /> : <>{t('loyalty::admin_points.expiring_on', { points: figure(locale, expiring.points), day: day(expiring.day) })}</>;
}

/**
 * Every live lot, soonest first (answer 11); nothing at all when there is none. Its words come from
 * the page that draws it - the panel's or the shop's - since each page carries only its own files.
 */
export function LotsTable({ lots, caption, pointsLabel, expiresLabel }: { lots: LotData[]; caption: string; pointsLabel: string; expiresLabel: string }) {
    if (lots.length === 0) {
        return null;
    }

    return (
        <section className="grid min-w-0 gap-3" data-test="lots">
            <h2 className="text-heading-16 text-ink">{caption}</h2>
            <div className="material-base max-w-full overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{caption}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead className="text-end">{pointsLabel}</TableHead>
                            <TableHead>{expiresLabel}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lots.map((lot, index) => (
                            <TableRow key={`${lot.expiresAt}-${index}`}>
                                <TableCell className="text-end">
                                    <Points value={lot.points} />
                                </TableCell>
                                <TableCell className="text-copy-13 text-ink-muted">
                                    <Time value={lot.expiresAt} mode="absolute" />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

/** A history line's points: green when they came in, the plain ink when they went - never red for an ordinary spend. */
export function SignedPoints({ value }: { value: number }) {
    return <Points value={value} signed className={value > 0 ? 'text-good' : 'text-ink'} />;
}

/** The staff history (L2): date, what, order, points, by whom, why. */
export function StaffHistory({ rows }: { rows: HistoryRowData[] }) {
    const t = useTranslator();

    return (
        <div className="material-base max-w-full overflow-x-auto">
            <Table>
                <TableCaption className="sr-only">{t('loyalty::admin_points.history')}</TableCaption>
                <TableHeader className="bg-surface-sunken">
                    <TableRow>
                        <TableHead>{t('loyalty::admin_points.columns.date')}</TableHead>
                        <TableHead>{t('loyalty::admin_points.columns.what')}</TableHead>
                        <TableHead>{t('loyalty::admin_points.columns.order')}</TableHead>
                        <TableHead className="text-end">{t('loyalty::admin_points.columns.points')}</TableHead>
                        <TableHead>{t('loyalty::admin_points.columns.by')}</TableHead>
                        <TableHead>{t('loyalty::admin_points.columns.reason')}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.id} data-test={`history-${row.id}`}>
                            <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">
                                <Time value={row.occurredAt} />
                            </TableCell>
                            <TableCell>{t(`loyalty::admin_points.kind.${row.kind}`)}</TableCell>
                            <TableCell>
                                {row.orderNumber === null ? (
                                    <Unknown />
                                ) : (
                                    <bdi dir="ltr" className="tw-figure">
                                        {row.orderNumber}
                                    </bdi>
                                )}
                            </TableCell>
                            <TableCell className="text-end">
                                <SignedPoints value={row.points} />
                            </TableCell>
                            <TableCell>{row.staffName === null ? <Unknown /> : <bdi>{row.staffName}</bdi>}</TableCell>
                            <TableCell className="max-w-80 text-copy-13 whitespace-normal text-ink-muted">
                                {row.reason === null ? <Unknown /> : <bdi className="break-words">{row.reason}</bdi>}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
