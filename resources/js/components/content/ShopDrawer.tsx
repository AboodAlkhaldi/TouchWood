import { Link, router, usePage } from '@inertiajs/react';
import { LayoutDashboard, LogOut, Moon, Sun, UserRound } from 'lucide-react';
import { choosePreference } from '@/components/Preferences';
import { CategoryTree } from '@/components/shop/CategoryTree';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The phone's drawer (content.md §1.5; the owner's option C3 of the design's Mobile Options, 2026-11-10/11): a Sheet from
| the page's start side. **A segmented control at the top** (shadcn Tabs) swaps **Categories** - Catalog's menu as a
| folding tree - and **Account & settings**: the staff view's menu first, for staff only; the greeting (or the way
| in); the verify-email badge; My Orders, Track a Shipment, Company Account; **the country switch**. **Language and
| theme are quick toggles at the bottom**, each showing what it switches to. Loaded only when the menu button is
| pressed, so no page carries it on its first paint.
|
| Must-keep items the design leaves out are here on a phone (design-compare): the staff view's menu, the shopper's
| name and the badge, the country and the language switches.
*/

type Props = {
    /** The same page in the other language, where the page knows it (a catalogue page's slug is its language's). */
    alternate: string | null;
};

/** What the drawer holds, under its head; the Sheet itself is the layout's (its parts are every page's already). */
export default function ShopDrawer({ alternate }: Props) {
    const page = usePage<SharedProps>();
    const { shop, shopper, staffView, locale, frame, theme } = page.props;
    const t = useTranslator();
    const link = useLink();
    const words = frame?.content?.words ?? {};
    const catalog = frame?.catalog;

    if (shop === null || shop === undefined) {
        return null;
    }

    const other = locale === 'ar' ? 'en' : 'ar';
    const dark = theme.mode === 'dark';
    const rows = [
        { key: 'orders', text: words.my_orders, href: link('storefront.sales.orders') },
        { key: 'track', text: words.track, href: link('storefront.sales.orders') },
        { key: 'company', text: words.company, href: link('storefront.company') },
    ];

    return (
        <>
                <Tabs defaultValue="categories" className="min-h-0 flex-1 gap-0">
                    <TabsList className="mx-4 mt-3 mb-1 grid h-10 grid-cols-2" data-test="drawer-switch">
                        <TabsTrigger value="categories" data-test="drawer-categories">
                            {words.categories}
                        </TabsTrigger>
                        <TabsTrigger value="account" data-test="drawer-account">
                            {words.account_settings}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="categories" className="min-h-0 overflow-y-auto px-4 py-2">
                        {catalog ? <CategoryTree menu={catalog.menu} words={catalog.words} /> : null}
                    </TabsContent>

                    <TabsContent value="account" className="min-h-0 overflow-y-auto py-2" data-test="drawer-account-view">
                        {/* The staff view's menu at the top, for staff only (the owner's fill-in, 2026-11-10). */}
                        {staffView ? (
                            <div className="grid gap-1 border-b border-line px-4 pb-3" data-test="drawer-staff">
                                <span className="truncate text-label-14 text-ink">{staffView.name}</span>
                                <a href="/admin" className="flex min-h-11 items-center gap-3 text-copy-14 text-ink" data-test="drawer-staff-panel">
                                    <LayoutDashboard aria-hidden="true" className="size-5 text-brand" />
                                    {t('admin.panel')}
                                </a>
                                <button
                                    type="button"
                                    className="flex min-h-11 items-center gap-3 text-start text-copy-14 text-ink"
                                    data-test="drawer-staff-leave"
                                    onClick={() => router.post(link('storefront.staff-view.leave'), { store: shop.code })}
                                >
                                    <LogOut aria-hidden="true" className="size-5 text-brand" />
                                    {t('admin.staff_view.leave')}
                                </button>
                            </div>
                        ) : (
                            <div className="flex items-center gap-3 bg-surface-sunken px-4 py-4">
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface">
                                    <UserRound aria-hidden="true" className="size-5 text-brand" />
                                </span>
                                <span className="grid min-w-0 gap-1">
                                    <span className="truncate text-label-14 text-ink">
                                        {shopper ? (words.hello ?? ':name').replace(':name', shopper.name) : words.hello_guest}
                                    </span>
                                    {shopper ? (
                                        shopper.emailVerified ? null : (
                                            <Badge asChild variant="outline" className="border-transparent bg-warn-soft text-warn">
                                                <Link href={link('storefront.verify-email')}>{t('access::auth.verify_pending')}</Link>
                                            </Badge>
                                        )
                                    ) : (
                                        <Button variant="outline" size="sm" asChild className="justify-self-start">
                                            <Link href={link('storefront.sign-in')} data-test="drawer-sign-in">
                                                {t('access::auth.sign_in')}
                                            </Link>
                                        </Button>
                                    )}
                                </span>
                            </div>
                        )}

                        {staffView ? null : (
                            <ul className="grid py-1">
                                {rows.map((row) => (
                                    <li key={row.key}>
                                        <Link href={row.href} className="flex min-h-12 items-center gap-3 px-4 text-copy-14 text-ink hover:bg-muted" data-test={`drawer-${row.key}`}>
                                            <span className="flex-1">{row.text}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {/* The country (the owner's fill-in): another country is another shop, so it goes to that store's home. */}
                        <label className="mx-4 mt-2 grid gap-2 border-t border-line pt-3 text-label-12 text-ink-muted">
                            <span className="flex items-center gap-2">
                                {words.country}
                            </span>
                            <NativeSelect
                                data-test="drawer-country"
                                value={shop.code}
                                aria-label={t('platform::stores.choose_title')}
                                onChange={(event) => router.visit(`/${event.target.value}/${locale}`)}
                                className="w-full"
                            >
                                {shop.available.map((store) => (
                                    <NativeSelectOption key={store.code} value={store.code}>
                                        {store.isActive ? store.name : `${store.name} (${t('admin.store.off')})`}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </label>

                        {/* Signing out, which the header's menu holds from 641 px: a post, last (Geist's Menu). */}
                        {shopper && !staffView ? (
                            <button
                                type="button"
                                className="mx-4 mt-3 flex min-h-12 w-[calc(100%-2rem)] items-center gap-3 border-t border-line text-start text-copy-14 text-bad"
                                data-test="drawer-sign-out"
                                onClick={() => router.post(link('storefront.account.sign-out'))}
                            >
                                <LogOut aria-hidden="true" className="size-5" />
                                {t('access::auth.sign_out')}
                            </button>
                        ) : null}
                    </TabsContent>
                </Tabs>

                {/* Quick toggles at the bottom: each says what it switches to. */}
                <div className="grid grid-cols-2 gap-2 border-t border-line px-4 pt-3 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                    {shop.languages.includes(other) ? (
                        <Button asChild variant="secondary" className="h-10">
                            <Link href={alternate ?? page.url.replace(`/${shop.code}/${locale}`, `/${shop.code}/${other}`)} lang={other} data-test={`drawer-language-${other}`}>
                                {other === 'ar' ? 'العربية' : 'English'}
                            </Link>
                        </Button>
                    ) : null}
                    <Button variant="secondary" className="h-10" data-test="drawer-theme" onClick={() => choosePreference('theme', dark ? 'light' : 'dark', '/preferences')}>
                        {dark ? <Sun aria-hidden="true" /> : <Moon aria-hidden="true" />}
                        {dark ? words.light : words.dark}
                    </Button>
                </div>
        </>
    );
}
