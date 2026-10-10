import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { ProductPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, nameIn, useLocale } from '../parts';
import { StageBadge } from './parts';

/*
| One product (catalog.md §4.4 S9): its name, stage and codes above **tabs kept in the address**
| (`?tab=`, owner 2026-10-07, #11) - Details, Variants, Photos, Search and Filters, Related; a store's
| rows come with the next step. A draft says what it still lacks to be made ready, in words.
|
| **Each tab is a page of its own** (DetailsTab, VariantsTab, ...), all of them this one product above
| it: the server reads the open tab's data only, and the browser loads the open tab's script only, so
| every one stays within both budgets (frontend.md §5) - and is rendered whole on the server, which a
| tab loaded later with React.lazy would not be (renderToString cannot wait for it).
|
| The main action follows the stage: Make Ready (a draft, out of reach with the reason until it is
| complete), Restore (an archived product). The ⋯ menu: Archive Product…, and last Delete Draft….
| Whoever may not change it - the job missing in a store where it is on (§1.1) - is told so once, at
| the top, and every control is out of reach.
*/

const TABS = ['details', 'variants', 'photos', 'search', 'related'] as const;

type Tab = (typeof TABS)[number];

const asTab = (value: string): Tab => (TABS as readonly string[]).includes(value) ? (value as Tab) : 'details';

// The tab just chosen: its page is a new one, and focus would fall to the page's top without this.
let chosenTab: Tab | null = null;

export function ProductShell({ page, children }: { page: ProductPage; children: ReactNode }) {
    const { product, missing, mayPublish, mayArchive, mayUpdate } = page;
    const t = useTranslator();
    const locale = useLocale();
    const name = nameIn(locale, product.nameAr, product.nameEn);
    const [open, setOpen] = useState<Tab>(asTab(page.tab));
    const [loading, setLoading] = useState(false);
    const [dialog, setDialog] = useState<'archive' | 'delete' | null>(null);
    const more = useRef<HTMLButtonElement>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => setOpen(asTab(page.tab)), [page.tab]);

    useEffect(() => {
        if (chosenTab !== null && chosenTab === page.tab) {
            document.querySelector<HTMLElement>(`[data-test="tab-${chosenTab}"]`)?.focus();
        }

        chosenTab = null;
    }, []);

    // A tab is opened by its address: its page, with its data; Back brings the one before.
    function choose(value: string) {
        const tab = asTab(value);
        setOpen(tab);
        chosenTab = tab;
        router.get(`/admin/products/${product.id}`, { tab }, { preserveScroll: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });
    }

    const post = (path: string) => router.post(`/admin/products/${product.id}/${path}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const counts: Record<Tab, number | null> = {
        details: null,
        variants: product.counts.variants,
        photos: product.counts.photos,
        search: product.counts.searchWords + product.counts.filterValues,
        related: product.counts.related + product.counts.goesWith,
    };

    return (
        <AdminLayout
            title={name}
            breadcrumbs={[{ label: t('catalog::admin_products.title'), href: '/admin/products' }]}
            action={
                <div className="flex items-center gap-2">
                    {product.stage === 'DRAFT' ? (
                        <ActionButton
                            loading={busy}
                            disabledReason={!mayPublish ? t('catalog::admin_products.publish_reason') : missing.length > 0 ? t('catalog::admin_products.not_complete') : undefined}
                            onClick={() => post('ready')}
                            data-test="make-ready"
                        >
                            {t('catalog::admin_products.make_ready')}
                        </ActionButton>
                    ) : null}
                    {product.stage === 'ARCHIVED' ? (
                        <ActionButton loading={busy} disabledReason={mayArchive ? undefined : t('catalog::admin_products.archive_reason')} onClick={() => post('restore')} data-test="restore-product">
                            {t('catalog::admin_products.restore')}
                        </ActionButton>
                    ) : null}
                    {product.stage !== 'ARCHIVED' && !mayArchive ? <MoreButtonOff name={name} reason={t('catalog::admin_products.archive_reason')} /> : null}
                    {product.stage !== 'ARCHIVED' && mayArchive ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <MoreButton ref={more} name={name} busy={busy} data-test="product-actions" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-56">
                                <DropdownMenuItem variant="destructive" onSelect={() => setDialog('archive')} data-test="archive-product">
                                    {t('catalog::admin_products.archive')}
                                </DropdownMenuItem>
                                {product.stage === 'DRAFT' ? (
                                    <DropdownMenuItem variant="destructive" onSelect={() => setDialog('delete')} data-test="delete-product">
                                        {t('catalog::admin_products.delete')}
                                    </DropdownMenuItem>
                                ) : null}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                </div>
            }
        >
            <div className="grid gap-6">
                <FormError />

                <div className="flex flex-wrap items-center gap-3">
                    <StageBadge stage={product.stage} />
                    {product.codes.length > 0 ? (
                        <span className="tw-figure font-mono text-copy-13 text-ink-muted" dir="ltr" data-test="product-codes">
                            {product.codes.join(' · ')}
                        </span>
                    ) : null}
                </div>

                {product.stage === 'DRAFT' && missing.length > 0 ? (
                    <Note variant="warning" data-test="missing">
                        <span className="grid gap-1">
                            <span>{t('catalog::admin_products.missing.title')}</span>
                            <ul className="list-disc ps-5">
                                {missing.map((item) => (
                                    <li key={item}>{t(`catalog::admin_products.missing.${item}`)}</li>
                                ))}
                            </ul>
                        </span>
                    </Note>
                ) : null}
                {product.hiddenByCategory ? <Note variant="warning">{t('catalog::admin_products.hidden.category')}</Note> : null}
                {product.hiddenByBrand ? <Note variant="warning">{t('catalog::admin_products.hidden.brand')}</Note> : null}
                {!mayUpdate ? <Note data-test="read-only">{t('catalog::admin_products.read_only')}</Note> : null}

                {/* min-w-0: five tabs are wider than a phone; their strip scrolls, never the page. Each tab is
                    read from the server, so the arrows move between them and Enter or Space opens one. */}
                <Tabs value={open} onValueChange={choose} activationMode="manual" className="min-w-0 gap-6">
                    <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t('catalog::admin_products.tabs_label')}>
                        {TABS.map((tab) => (
                            <TabsTrigger key={tab} value={tab} data-test={`tab-${tab}`} className="flex-none gap-2 px-3 text-label-14">
                                {t(`catalog::admin_products.tab.${tab}`)}
                                {counts[tab] !== null && (counts[tab] ?? 0) > 0 ? <span className="tw-figure text-copy-12 text-ink-muted">{figure(locale, counts[tab] ?? 0)}</span> : null}
                            </TabsTrigger>
                        ))}
                    </TabsList>

                    {loading || page.tab !== open ? (
                        <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                            <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                            {t('ui.loading')}
                        </p>
                    ) : (
                        <TabsContent value={open}>{children}</TabsContent>
                    )}
                </Tabs>
            </div>

            {dialog !== null ? (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(next) => (next ? undefined : setDialog(null))}
                    returnFocusTo={more}
                    title={t(dialog === 'archive' ? 'catalog::admin_products.archive_title' : 'catalog::admin_products.delete_title')}
                    description={t(dialog === 'archive' ? 'catalog::admin_products.archive_body' : 'catalog::admin_products.delete_body', { name })}
                    busy={busy}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(`/admin/products/${product.id}/${dialog}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setDialog(null) })
                            }
                            data-test={`confirm-${dialog}-product`}
                        >
                            {t(dialog === 'archive' ? 'catalog::admin_products.archive_title' : 'catalog::admin_products.delete_title')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            ) : null}
        </AdminLayout>
    );
}
