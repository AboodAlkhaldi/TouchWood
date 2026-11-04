import { useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { SharedProps } from '@/types/page';

/*
| What Customer Messages and Offer Notices' lists share (ops.md §4.15 O3, O5): the status in words, who it reached, the
| read-back dialog, Refresh while something sends. Nothing here reads the server by itself on a clock (Q7, Q15).
*/

const LOOKS: Record<string, Tone> = { SCHEDULED: 'blue-subtle', SENDING: 'amber-subtle', SENT: 'green-subtle', CANCELLED: 'gray-subtle', FAILED: 'red-subtle' };

export function WrittenStatus({ status }: { status: string }) {
    const t = useTranslator();

    return (
        <Badge className={tone(LOOKS[status] ?? 'gray-subtle')} data-test="status">
            {t(`ops::admin_written.status.${status}`)}
        </Badge>
    );
}

export function channelWords(row: { byBell: boolean; byEmail: boolean }, t: ReturnType<typeof useTranslator>): string {
    return [row.byBell ? t('ops::admin_written.by.bell') : null, row.byEmail ? t('ops::admin_written.by.email') : null].filter(Boolean).join(' · ');
}

/** Bell entries, emails sent, and emails failed when above 0, each labelled. */
export function Reached({ row }: { row: { bellCount: number; emailCount: number; failedCount: number } }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;

    return (
        <span className="grid gap-0.5 whitespace-nowrap text-copy-13 text-ink-muted" data-test="reached">
            <span>{t('ops::admin_written.reached.bell', { count: figure(locale, row.bellCount) })}</span>
            <span>{t('ops::admin_written.reached.email', { count: figure(locale, row.emailCount) })}</span>
            {row.failedCount > 0 ? <span className="text-bad">{t('ops::admin_written.reached.failed', { count: figure(locale, row.failedCount) })}</span> : null}
        </span>
    );
}

/** Shown only while a row says Sending: the one way to read the list again (Q15). */
export function RefreshWhileSending({ rows, only }: { rows: { status: string }[]; only: string[] }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    if (!rows.some((row) => row.status === 'SENDING')) {
        return null;
    }

    return (
        <div>
            <ActionButton type="button" variant="outline" loading={busy} onClick={() => router.reload({ only, onStart: () => setBusy(true), onFinish: () => setBusy(false) })} data-test="refresh">
                {t('ops::admin_written.refresh')}
            </ActionButton>
        </div>
    );
}

function Line({ label, dir, children }: { label: string; /** The text's own direction: its label stays the page's. */ dir?: 'rtl' | 'ltr'; children: ReactNode }) {
    return (
        <div className="grid gap-1">
            <dt className="text-label-13 text-ink-muted">{label}</dt>
            <dd dir={dir} className="text-copy-14 wrap-anywhere whitespace-pre-line text-ink">
                {children}
            </dd>
        </div>
    );
}

export type ReadBack = {
    title: string;
    audience: string;
    /** "It holds :count members now", for a segment still there. */
    holds: number | null;
    row: { titleAr: string; titleEn: string; bodyAr: string; bodyEn: string; byBell: boolean; byEmail: boolean; status: string; bellCount: number; emailCount: number; failedCount: number };
    /** What is particular to the kind: who wrote it, when, a notice's link. */
    lines: ReactNode;
};

/** Pressing a row (Q13): everything it said and did, with no new read. */
export function ReadBackDialog({ open, onClose }: { open: ReadBack; onClose: () => void }) {
    const t = useTranslator();
    const { locale, menu } = usePage<SharedProps>().props;
    const failedJobs = menu.flatMap((group) => group.entries).find((entry) => entry.key === 'failed_jobs');
    const { row } = open;

    return (
        <PanelDialog wide open onOpenChange={(next) => (next ? undefined : onClose())} title={open.title} description={channelWords(row, t)} busy={false} confirm={null} cancelLabel={t('ops::admin_written.read_back.close')}>
            {row.status === 'FAILED' ? (
                <Note
                    variant="error"
                    action={
                        failedJobs === undefined ? undefined : (
                            <Button asChild size="sm" variant="outline">
                                <Link href={failedJobs.href} data-test="failed-jobs">
                                    {t('ops::admin_written.failed_link')}
                                </Link>
                            </Button>
                        )
                    }
                    data-test="failed-note"
                >
                    {t('ops::admin_written.failed_note')}
                </Note>
            ) : null}
            <dl className="grid gap-3 sm:grid-cols-2" data-test="read-back">
                <Line label={t('ops::admin_written.read_back.to')}>
                    {open.audience}
                    {open.holds === null ? null : (
                        <span className="block text-copy-13 text-ink-muted" data-test="holds">
                            {open.holds === 1 ? t('ops::admin_written.audience.holds_one') : t('ops::admin_written.audience.holds', { count: figure(locale, open.holds) })}
                        </span>
                    )}
                </Line>
                <Line label={t('ops::admin_written.read_back.status')}>
                    <WrittenStatus status={row.status} />
                </Line>
                <Line dir="rtl" label={t('ops::admin_written.read_back.title_ar')}>
                    {row.titleAr}
                </Line>
                <Line dir="ltr" label={t('ops::admin_written.read_back.title_en')}>
                    {row.titleEn}
                </Line>
                <Line dir="rtl" label={t('ops::admin_written.read_back.body_ar')}>
                    {row.bodyAr}
                </Line>
                <Line dir="ltr" label={t('ops::admin_written.read_back.body_en')}>
                    {row.bodyEn}
                </Line>
                <Line label={t('ops::admin_written.read_back.reached')}>
                    <Reached row={row} />
                </Line>
                {open.lines}
            </dl>
        </PanelDialog>
    );
}

export { Line as ReadBackLine };

export function SentAt({ value }: { value: string }) {
    return <Time value={value} mode="absolute" />;
}
