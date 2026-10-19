import { lazy, Suspense, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Time } from '@/components/Time';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { CategoryDiscountPage, DiscountPreviewData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { DiscountOff } from './discount-parts';
import { DiscountPreview } from './DiscountPreview';
import { Guarded, Mark, MoreButton, StateBadge, StoreFrame, useT } from './parts';

// The dialog loads when it is first opened, not with the page.
const DiscountDialog = lazy(() => import('./DiscountDialog').then((module) => ({ default: module.DiscountDialog })));

/*
| **A category discount's page** (pricing.md §4.3.3 P3): Category Discounts › its category; its fields; what
| it reaches as of today while scheduled or running ("Ended - nothing is reached now." once ended); and the
| actions of its state - scheduled: **Change Discount**, ⋯ Remove Discount…; running: **Change End**, ⋯ End
| Discount…; ended: none - for whoever manages category discounts in its store.
*/

type Dialog = 'change' | 'change_end' | 'end' | 'remove' | null;

export default function CategoryDiscount(page: CategoryDiscountPage) {
    const { storeFrame: frame, discount, preview } = page;
    const t = useT();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const more = useRef<HTMLButtonElement>(null);
    const title = discount.categoryPath.join(' › ');
    const manage = frame.mayManageDiscounts && discount.state !== 'ENDED';
    const open = (next: Dialog, trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog(next);
    };
    const action = manage ? (
        <div className="flex items-center gap-2">
            <ActionButton type="button" onClick={(event) => open(discount.state === 'SCHEDULED' ? 'change' : 'change_end', event.currentTarget)} data-test="change-discount">
                {discount.state === 'SCHEDULED' ? t('pricing::admin_discounts.change') : t('pricing::admin_discounts.change_end')}
            </ActionButton>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <MoreButton ref={more} name={title} data-test="discount-actions" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-56">
                    {discount.state === 'SCHEDULED' ? (
                        <Guarded destructive label={t('pricing::admin_discounts.remove_item')} onSelect={() => open('remove', more.current)} test="remove-discount" />
                    ) : (
                        <Guarded destructive label={t('pricing::admin_discounts.end_item')} onSelect={() => open('end', more.current)} test="end-discount" />
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    ) : undefined;
    // The next 20 products, read as the page reads them (a partial visit that keeps the page).
    const morePreview = (after: string) =>
        new Promise<DiscountPreviewData | null>((resolve) => {
            router.get(`/admin/category-discounts/${discount.id}`, { after }, {
                only: ['preview'],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onSuccess: (next) => resolve((next.props as unknown as CategoryDiscountPage).preview),
                onError: () => resolve(null),
            });
        });

    return (
        <AdminLayout title={title} subtitle={t('pricing::admin_discounts.subtitle')} action={action} breadcrumbs={[{ label: t('pricing::admin_discounts.title'), href: `/admin/category-discounts?store=${frame.storeCode}` }]}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={{ ...frame, stores: [] }} />

                <Card className="material-base border-0">
                    <CardHeader>
                        <CardTitle className="text-heading-16 text-ink">{t('pricing::admin_discounts.page.fields')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-8 gap-y-3 text-copy-14" data-test="discount-fields">
                            <dt className="text-ink-muted">{t('pricing::admin_discounts.column.category')}</dt>
                            <dd>{title}</dd>
                            <dt className="text-ink-muted">{t('pricing::admin_discounts.column.off')}</dt>
                            <dd>
                                <DiscountOff discount={discount} />
                            </dd>
                            <dt className="text-ink-muted">{t('pricing::admin_discounts.column.lines')}</dt>
                            <dd>{t(`pricing::admin.lines.${discount.lines}`)}</dd>
                            <dt className="text-ink-muted">{t('pricing::admin.column.starts')}</dt>
                            <dd>
                                <Time value={discount.startsAt} mode="absolute" />
                            </dd>
                            <dt className="text-ink-muted">{t('pricing::admin.column.ends')}</dt>
                            <dd>{discount.endsAt === null ? t('pricing::admin.no_end') : <Time value={discount.endsAt} mode="absolute" />}</dd>
                            <dt className="text-ink-muted">{t('pricing::admin.column.state')}</dt>
                            <dd>
                                <StateBadge state={discount.state} />
                            </dd>
                            <dt className="text-ink-muted">{t('pricing::admin.column.always_wins')}</dt>
                            <dd>{discount.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : '—'}</dd>
                            <dt className="text-ink-muted">{t('pricing::admin_discounts.column.left_out')}</dt>
                            <dd>{discount.leftOutNames.length === 0 ? t('pricing::admin_discounts.none') : discount.leftOutNames.join(t('pricing::admin.list_separator'))}</dd>
                        </dl>
                    </CardContent>
                </Card>

                {discount.state === 'ENDED' ? (
                    <Note variant="secondary" data-test="discount-ended">
                        {t('pricing::admin_discounts.preview.ended')}
                    </Note>
                ) : (
                    <DiscountPreview preview={preview} zone={page.storeTimezone} more={morePreview} />
                )}
            </div>

            {dialog === 'change' || dialog === 'change_end' ? (
                <Suspense fallback={null}>
                    <DiscountDialog
                        mode={dialog}
                        discount={discount}
                        store={{ code: frame.storeCode, decimals: frame.decimals, currency: frame.currency, zone: page.storeTimezone, ceilingPercent: frame.ceilingPercent, wired: frame.wired }}
                        open
                        onOpenChange={(next) => (next ? undefined : setDialog(null))}
                        returnFocusTo={opener}
                    />
                </Suspense>
            ) : null}
            {dialog === 'end' || dialog === 'remove' ? <ConfirmDiscount kind={dialog} id={discount.id} store={frame.storeCode} onClose={() => setDialog(null)} opener={opener} /> : null}
        </AdminLayout>
    );
}

/** End Discount / Remove Discount: asked first, as an offer's; a removed one's page is gone, so its list follows. */
function ConfirmDiscount({ kind, id, store, onClose, opener }: { kind: 'end' | 'remove'; id: string; store: string; onClose: () => void; opener: React.RefObject<HTMLElement | null> }) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const title = kind === 'end' ? t('pricing::admin_discounts.end') : t('pricing::admin_discounts.remove');

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={(next) => (next ? undefined : onClose())}
            returnFocusTo={opener}
            title={title}
            description={kind === 'end' ? t('pricing::admin_discounts.end_body') : t('pricing::admin_discounts.remove_body')}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/category-discounts/${id}/${kind}`, kind === 'remove' ? { list_store: store } : {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}
                    data-test={`confirm-${kind}-discount`}
                >
                    {title}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
