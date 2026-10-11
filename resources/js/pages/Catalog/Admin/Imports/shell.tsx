import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Time } from '@/components/Time';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { ImportPage, ImportWaitingData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, useLocale } from '../parts';
import { ConfirmByName, ImportStateBadge, isDeciding } from './parts';

/*
| One import (catalog.md §4.4 S11, §1.12): its file above **four parts kept in the address**
| (`?part=`, P12) - Names, Codes, Before Bringing In, Products - each with how many still wait, and
| **each a page of its own** (NamesPart, CodesPart, …), the server reading the open part only (P13),
| as a product's tabs are (lesson 180).
|
| **The main action follows the state**: Bring Products In while deciding - out of reach, with what
| still waits, until nothing does -; while bringing in, a line saying so, the page asking again every
| few seconds until it is done (P12); failed, the reason and that nothing was kept, and Bring Products
| In again once it is mended (§1.12) - every decision kept. **Discard
| Import…** (⋯, deciding or failed) asks for the file's name typed (P10).
*/

const PARTS = ['names', 'codes', 'before', 'products'] as const;
type Part = (typeof PARTS)[number];
const asPart = (value: string): Part => ((PARTS as readonly string[]).includes(value) ? (value as Part) : 'names');

/** How often the page asks again while the products are brought in. */
const POLL_MS = 3000;

// The part just chosen: its page is a new one, and focus would fall to the page's top without this.
let chosenPart: Part | null = null;

export function waitingTotal(waiting: ImportWaitingData): number {
    return waiting.names + waiting.codes + waiting.addresses + waiting.sales + waiting.codeChanges + waiting.attributeChanges;
}

export function ImportShell({ page, children }: { page: ImportPage; children: ReactNode }) {
    const { import: file, waiting } = page;
    const t = useTranslator();
    const locale = useLocale();
    const [open, setOpen] = useState<Part>(asPart(page.part));
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [discarding, setDiscarding] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const deciding = isDeciding(file.state);
    const total = waitingTotal(waiting);
    const mayDiscard = deciding;

    useEffect(() => setOpen(asPart(page.part)), [page.part]);

    useEffect(() => {
        if (chosenPart !== null && chosenPart === page.part) {
            document.querySelector<HTMLElement>(`[data-test="part-${chosenPart}"]`)?.focus();
        }

        chosenPart = null;
    }, []);

    // Bringing in runs on the queue: the page asks again until the import is in, or failed.
    useEffect(() => {
        if (file.state !== 'BRINGING_IN') {
            return;
        }

        const timer = window.setInterval(() => router.reload(), POLL_MS);

        return () => window.clearInterval(timer);
    }, [file.state]);

    function choose(value: string) {
        const part = asPart(value);
        setOpen(part);
        chosenPart = part;
        router.get(`/admin/imports/${file.id}`, { part }, { preserveScroll: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });
    }

    const counts: Record<Part, number> = {
        names: waiting.names,
        codes: waiting.codes + waiting.sales + waiting.codeChanges + waiting.attributeChanges,
        before: waiting.addresses,
        products: page.toAccept,
    };
    const waitingItems = (
        [
            ['names', waiting.names],
            ['codes', waiting.codes],
            ['addresses', waiting.addresses],
            ['sales', waiting.sales],
            ['code_changes', waiting.codeChanges],
            ['attribute_changes', waiting.attributeChanges],
        ] as const
    ).filter(([, count]) => count > 0);

    return (
        <AdminLayout
            title={file.fileName}
            breadcrumbs={[{ label: t('catalog::admin_imports.title'), href: '/admin/imports' }]}
            action={
                <div className="flex items-center gap-2">
                    {deciding ? (
                        <ActionButton
                            loading={busy}
                            disabledReason={total > 0 ? t('catalog::admin_imports.waiting_reason') : undefined}
                            onClick={() => router.post(`/admin/imports/${file.id}/bring-in`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                            data-test="bring-in"
                        >
                            {t('catalog::admin_imports.bring_in')}
                        </ActionButton>
                    ) : null}
                    {mayDiscard ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <MoreButton ref={more} name={file.fileName} busy={busy} data-test="import-actions" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-56">
                                <DropdownMenuItem variant="destructive" onSelect={() => setDiscarding(true)} data-test="discard-import">
                                    {t('catalog::admin_imports.discard')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : (
                        <MoreButtonOff name={file.fileName} reason={t('catalog::admin_imports.discard_reason')} />
                    )}
                </div>
            }
        >
            <div className="grid gap-6">
                <FormError />

                <div className="flex flex-wrap items-center gap-3 text-copy-14 text-ink-muted">
                    <ImportStateBadge state={file.state} />
                    {file.uploadedBy !== null ? <span>{t('catalog::admin_imports.uploaded_by', { name: file.uploadedBy })}</span> : null}
                    <Time value={file.uploadedAt} />
                    <span className="tw-figure">{t('catalog::admin_imports.column.products')}: {figure(locale, file.products)}</span>
                    {file.withPhotos ? <span>{t('catalog::admin_imports.with_photos')}</span> : null}
                </div>

                {deciding && waitingItems.length > 0 ? (
                    <Note data-test="waiting">
                        <span className="grid gap-1">
                            <span>{t('catalog::admin_imports.waiting_title')}</span>
                            <ul className="tw-figure list-disc ps-5">
                                {waitingItems.map(([key, count]) => (
                                    <li key={key}>{t(`catalog::admin_imports.waiting.${key}`, { count: figure(locale, count) })}</li>
                                ))}
                            </ul>
                        </span>
                    </Note>
                ) : null}
                {file.state === 'BRINGING_IN' ? (
                    <Note data-test="bringing-in">
                        <span className="flex items-center gap-2">
                            <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                            {t('catalog::admin_imports.bringing_in')}
                        </span>
                    </Note>
                ) : null}
                {file.state === 'FAILED' ? (
                    <Note variant="error" data-test="failed">
                        {t('catalog::admin_imports.failed', { reason: file.failure ?? '' })}
                    </Note>
                ) : null}
                {file.state === 'IN' ? <Note data-test="in">{t('catalog::admin_imports.in')}</Note> : null}

                {/* min-w-0: four parts are wider than a phone; their strip scrolls, never the page. */}
                <Tabs value={open} onValueChange={choose} activationMode="manual" className="min-w-0 gap-6">
                    <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t('catalog::admin_imports.parts_label')}>
                        {PARTS.map((part) => (
                            <TabsTrigger key={part} value={part} data-test={`part-${part}`} className="flex-none gap-2 px-3 text-label-14">
                                {t(`catalog::admin_imports.part.${part}`)}
                                {counts[part] > 0 ? <span className="tw-figure text-copy-12 text-ink-muted">{figure(locale, counts[part])}</span> : null}
                            </TabsTrigger>
                        ))}
                    </TabsList>

                    {loading || page.part !== open ? (
                        <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                            <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                            {t('ui.loading')}
                        </p>
                    ) : (
                        <TabsContent value={open}>{children}</TabsContent>
                    )}
                </Tabs>
            </div>

            {discarding ? (
                <ConfirmByName
                    fileName={file.fileName}
                    title={t('catalog::admin_imports.discard_title')}
                    body={t('catalog::admin_imports.discard_body')}
                    confirm={t('catalog::admin_imports.discard_confirm')}
                    busy={busy}
                    onConfirm={() => router.post(`/admin/imports/${file.id}/discard`, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                    onOpenChange={(next) => (next ? undefined : setDiscarding(false))}
                    returnFocusTo={more}
                    testId="confirm-discard"
                />
            ) : null}
        </AdminLayout>
    );
}
