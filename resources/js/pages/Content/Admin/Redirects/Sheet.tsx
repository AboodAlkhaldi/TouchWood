import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { PhoneCards } from '@/components/PhoneCards';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { RedirectSheetPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W11b · the checked sheet (content.md §4.4, §1.15): a tab per result with its count (New · Same · Change · Refused), each
| line with its row, its addresses and, refused, why. Nothing is written until Apply Sheet, which asks "Write N lines?"
| - the new and the changed ones; it never deletes a line.
*/

const LIST = '/admin/content/redirects';
const RESULTS = ['NEW', 'SAME', 'CHANGE', 'REFUSED'] as const;

export default function Sheet({ uploadId, lines, counts, applied, tab }: RedirectSheetPageData) {
    const t = useTranslator();
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const writes = (counts.NEW ?? 0) + (counts.CHANGE ?? 0);
    const shown = lines.filter((line) => line.result === tab);
    const names: Record<string, string> = {
        NEW: t('content::admin_redirects.sheet.tabs.NEW'),
        SAME: t('content::admin_redirects.sheet.tabs.SAME'),
        CHANGE: t('content::admin_redirects.sheet.tabs.CHANGE'),
        REFUSED: t('content::admin_redirects.sheet.tabs.REFUSED'),
    };
    const reason = (rule: string | null) => (rule === null ? '' : t(`content::errors.redirect_invalid.rules.${rule}`));

    return (
        <AdminLayout
            title={t('content::admin_redirects.sheet.title')}
            subtitle={t('content::admin_redirects.sheet.subtitle')}
            breadcrumbs={[{ label: t('content::admin_redirects.title'), href: LIST }]}
            action={
                <ActionButton
                    type="button"
                    disabledReason={applied ? t('content::admin_redirects.sheet.applied_already') : writes === 0 ? t('content::admin_redirects.sheet.nothing') : undefined}
                    onClick={() => setAsking(true)}
                    data-test="apply-sheet"
                >
                    {t('content::admin_redirects.sheet.apply')}
                </ActionButton>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                {applied ? (
                    <Note variant="success" size="small" action={<Link href={LIST}>{t('content::admin_redirects.sheet.back')}</Link>}>
                        {t('content::admin_redirects.sheet.applied_already')}
                    </Note>
                ) : null}
                <div className="min-w-0 max-w-full overflow-x-auto">
                    <Tabs value={tab} onValueChange={(next) => router.get(`${LIST}/sheets/${uploadId}`, { tab: next }, { preserveScroll: true, replace: true })}>
                        <TabsList variant="line">
                            {RESULTS.map((result) => (
                                <TabsTrigger key={result} value={result} data-test={`tab-${result}`}>
                                    {names[result]}
                                    <span className="tw-figure text-ink-muted">{counts[result] ?? 0}</span>
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                </div>
                {shown.length === 0 ? (
                    <Empty className="material-base" data-test="sheet-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_redirects.sheet.none')}</EmptyTitle>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{names[tab]}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-16">{t('content::admin_redirects.sheet.columns.line')}</TableHead>
                                        <TableHead>{t('content::admin_redirects.sheet.columns.old')}</TableHead>
                                        <TableHead>{t('content::admin_redirects.sheet.columns.new')}</TableHead>
                                        {tab === 'REFUSED' ? <TableHead>{t('content::admin_redirects.sheet.columns.reason')}</TableHead> : null}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shown.map((line) => (
                                        <TableRow key={line.line} data-test={`sheet-line-${line.line}`}>
                                            <TableCell className="tw-figure">{line.line}</TableCell>
                                            <TableCell className="max-w-56 truncate font-mono text-copy-13" dir="ltr">
                                                {line.old}
                                            </TableCell>
                                            <TableCell className="max-w-56 truncate font-mono text-copy-13 text-ink-muted" dir="ltr">
                                                {line.new}
                                            </TableCell>
                                            {tab === 'REFUSED' ? <TableCell className="min-w-48 text-copy-13 text-ink">{reason(line.reason)}</TableCell> : null}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {/* On a phone each line a card (round 3 #15): its old address, its new one, its row and, refused, why. */}
                        <PhoneCards
                            test="sheet-line"
                            cards={shown.map((line) => ({
                                id: String(line.line),
                                title: (
                                    <span dir="ltr" className="font-mono">
                                        {line.old}
                                    </span>
                                ),
                                lines: [
                                    <span key="new" dir="ltr" className="font-mono">
                                        {line.new}
                                    </span>,
                                    <span key="line" className="tw-figure">{`${t('content::admin_redirects.sheet.columns.line')}: ${line.line}`}</span>,
                                    ...(tab === 'REFUSED' ? [<span key="why">{`${t('content::admin_redirects.sheet.columns.reason')}: ${reason(line.reason)}`}</span>] : []),
                                ],
                            }))}
                        />
                    </>
                )}
                <div>
                    <Button asChild variant="outline">
                        <Link href={LIST}>{t('content::admin_redirects.sheet.back')}</Link>
                    </Button>
                </div>
            </div>
            {asking ? (
                <PanelDialog
                    open
                    onOpenChange={(open) => (open ? undefined : setAsking(false))}
                    title={t('content::admin_redirects.sheet.apply_title', { count: writes })}
                    description={t('content::admin_redirects.sheet.apply_body')}
                    busy={busy}
                    confirm={
                        <ActionButton
                            loading={busy}
                            onClick={() => router.post(`${LIST}/sheets/${uploadId}/apply`, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                            data-test="confirm-apply"
                        >
                            {t('content::admin_redirects.sheet.apply')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            ) : null}
        </AdminLayout>
    );
}
