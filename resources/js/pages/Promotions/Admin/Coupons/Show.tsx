import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { FormError, useFreshRefusal } from '@/components/FormError';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { CouponStateBadge, useLocale } from '@/components/promotions/parts';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { CouponForm } from '@/pages/Promotions/Admin/Coupons/CouponForm';
import type { CouponFormData, CouponPage } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M2 - a coupon's page, or a new coupon's (promotions.md §4.1): Details, Uses and - for a coupon given
| to customers - Customers (owner's Q3); a new coupon has Details only. Uses and Customers are read only
| when their tab opens, a page at a time with Show More.
|
| A manager (Q16) has Turn Off / Turn On in the header, saved at once, and Delete Coupon… while it was
| never used - out of reach once used, the reason in its tooltip (Q6); Give to a Segment… on Customers
| (Q14) and Remove on each unused assignment.
*/

// Uses and Customers load when their tab opens, not with the page (frontend.md §5, the page budget). Never on the
// server's render, which cannot wait for them: a page opened at one of them draws it once it is awake.
const Uses = lazy(() => import('./CouponTabs').then((module) => ({ default: module.Uses })));
const Customers = lazy(() => import('./CouponTabs').then((module) => ({ default: module.Customers })));

const LIST = '/admin/coupons';
type Tab = 'details' | 'uses' | 'customers';

export default function Show(page: CouponPage) {
    const t = useTranslator();
    const locale = useLocale();
    const coupon = page.coupon;
    const tabs: Tab[] = coupon === null ? ['details'] : coupon.eligibility === 'ASSIGNED' ? ['details', 'uses', 'customers'] : ['details', 'uses'];
    const tab: Tab = tabs.includes(page.tab as Tab) ? (page.tab as Tab) : 'details';
    // The code isolated: one that starts with digits keeps its order on an Arabic page.
    const title = coupon === null ? t('promotions::admin_coupons.show.new_title') : isolate(coupon.code);
    const [awake, setAwake] = useState(false);
    useEffect(() => setAwake(true), []);

    return (
        <AdminLayout
            title={title}
            subtitle={page.storeName}
            breadcrumbs={[{ label: t('promotions::admin_coupons.list.title'), href: `${LIST}?store=${encodeURIComponent(page.storeCode)}` }]}
            action={coupon === null || !page.mayManage ? undefined : <HeaderActions coupon={coupon} />}
        >
            <div className="grid gap-4">
                <FormError />
                {page.storeIsActive ? null : (
                    <div>
                        <StoreOffBadge />
                    </div>
                )}
                {coupon === null ? null : (
                    <div className="flex flex-wrap items-center gap-3 text-copy-14 text-ink-muted" data-test="coupon-facts">
                        <CouponStateBadge state={coupon.state} />
                        <span>{t('promotions::admin_coupons.show.used', { count: figure(locale, coupon.usesTotal) })}</span>
                    </div>
                )}

                {tabs.length === 1 ? (
                    <CouponForm page={page} />
                ) : (
                    // Each tab is read from the server: the arrows move between them, Enter or Space opens one (as
                    // Products' tabs). The page keeps its state, so the focus stays on the tab and Details keeps
                    // what was typed while another tab is read.
                    <Tabs value={tab} activationMode="manual" onValueChange={(next) => router.get(`${LIST}/${coupon?.id}`, { tab: next }, { preserveScroll: true, preserveState: true, replace: true })}>
                        <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t('promotions::admin_coupons.show.tabs_label')}>
                            {tabs.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`} className="flex-none px-3 text-label-14">
                                    {t(`promotions::admin_coupons.show.tab_${each}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        <TabsContent value="details" forceMount className="pt-4 data-[state=inactive]:hidden">
                            <CouponForm page={page} />
                        </TabsContent>
                        <TabsContent value="uses" className="pt-4">
                            {awake && tab === 'uses' && coupon !== null ? (
                                <Suspense fallback={null}>
                                    <Uses page={page} coupon={coupon} />
                                </Suspense>
                            ) : null}
                        </TabsContent>
                        <TabsContent value="customers" className="pt-4">
                            {awake && tab === 'customers' && coupon !== null ? (
                                <Suspense fallback={null}>
                                    <Customers page={page} coupon={coupon} />
                                </Suspense>
                            ) : null}
                        </TabsContent>
                    </Tabs>
                )}
            </div>
        </AdminLayout>
    );
}

function HeaderActions({ coupon }: { coupon: CouponFormData }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const remove = useForm({});
    const deleteButton = useRef<HTMLButtonElement>(null);
    const used = coupon.usesTotal > 0;
    // Only the delete's own refusal, never an older one (useFreshRefusal).
    const refusal = useFreshRefusal(deleting);

    return (
        <div className="flex flex-wrap gap-2">
            <ActionButton
                type="button"
                variant="outline"
                loading={busy}
                data-test="switch-coupon"
                onClick={() => router.post(`${LIST}/${coupon.id}/switch`, { active: !coupon.active }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
            >
                {coupon.active ? t('promotions::admin_coupons.show.turn_off') : t('promotions::admin_coupons.show.turn_on')}
            </ActionButton>
            <ActionButton ref={deleteButton} type="button" variant="outline" className="text-bad" disabledReason={used ? t('promotions::admin_coupons.show.delete_used') : undefined} onClick={() => setDeleting(true)} data-test="delete-coupon">
                {`${t('promotions::admin_coupons.show.delete')}…`}
            </ActionButton>
            <DestructiveActionDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={t('promotions::admin_coupons.show.delete_title')}
                confirmLabel={t('promotions::admin_coupons.show.delete_title')}
                description={t('promotions::admin_coupons.show.delete_body', { code: isolate(coupon.code) })}
                irreversibleDescription={t('promotions::admin_coupons.show.delete_irreversible', { code: isolate(coupon.code) })}
                verificationPhrase={coupon.code}
                verificationLabel={t('promotions::admin_coupons.show.verification_label')}
                loading={remove.processing}
                error={refusal}
                onConfirm={() => remove.post(`${LIST}/${coupon.id}/delete`, { onSuccess: () => setDeleting(false) })}
                returnFocusTo={deleteButton}
            />
        </div>
    );
}
