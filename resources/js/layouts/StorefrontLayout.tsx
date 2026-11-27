import { type ComponentType, lazy, type ReactNode, Suspense, useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, Heart, House, LayoutDashboard, LogOut, Menu, Search, ShoppingCart, UserRound } from 'lucide-react';
import { Bell } from '@/components/ops/Bell';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { choosePreference } from '@/components/Preferences';
import { ThemeMenuSwitcher } from '@/components/geist-only/ThemeSwitcher';
import { Logo } from '@/components/Logo';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CategoryMenuBar } from '@/components/shop/CategoryMenu';
import { SearchBox } from '@/components/shop/SearchBox';
import { figure, intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { ContentLink } from '@/components/content/ContentLink';
import { useTranslator } from '@/lib/t';
import type { ShopAddressData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { ContentAlternateData, ContentHeadData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { ContentFrame, SharedProps, ShopperLine } from '@/types/page';

const NewsletterBox = lazy(() => import('@/components/ops/NewsletterBox'));
// What the phone's drawer holds: drawn only once it opens, never on the first paint (lesson 180).
const ShopDrawer = lazy(() => import('@/components/content/ShopDrawer'));
// The phone's country chip (owner, 2026-11-27, option A), in the drawer's file: loaded once the page has drawn, as the
// newsletter box is, so no shop page carries it and the build's chunks stay as they were (the page budget).
const CountryChip = lazy(() => import('@/components/content/ShopDrawer').then((drawer) => ({ default: drawer.CountryChip })));
const TabBar = lazy(() => import('@/components/content/TabBar'));

/** Whether this tab has drawn a page once: only then may the box draw at once (the server never draws it). */
let boxDrawn = false;


/*
| The shop's frame (frontend.md §2.3; content.md §1.5), on shadcn's code with Geist's rules (§1.11), to the design.
|
| From the top: **the announcement bar** (Content's, a live theme's dress over it), **the sticky header** - on a phone
| (640 or less) 52 px of menu, logo, bell and favourites with the search row under it; wider, the logo, the
| search box, the country, the language, the bell, favourites, the cart and the shopper (the theme in its menu,
| flags-2026-11.md S1.3), and Catalog's
| category row - the staff view's line, the shopper lines, the page, **the footer** per store (Ops' newsletter box; the
| theme switch left it, S1.3), and on a phone **the tab bar**: Home, Explore (opens search), Cart, Favourites, My Account.
| The phone's menu opens **the drawer** (the owner's option C3, `ShopDrawer`), loaded when pressed.
|
| What modules put in the frame comes through Platform's list of its parts (`frame`, catalog.md §4.5), in the order
| they always had: Catalog's menu and search box, Ops' bell before Sales' cart, then Content's bar, footer and
| pop-up. **The country and the language are both in the address**, not in a cookie the page reads: a shop page lives
| at /{store}/{locale}, so switching either is a link to the same page written the other way.
|
| The page's ground is the theme's (`--tw-backdrop`, content.md §1.8 part 11): its edges' artwork, wider than 1024 px.
| Arabic mirrors the whole frame, because the page's dir is set on <html> by the server.
*/

type Props = {
    title: string;
    children: ReactNode;
    /**
     * A page's own address and the same page's in the other language (catalog.md §4.5, P19), and
     * whether search engines are kept out of it. Pages without one keep the language switch's rule. Content's pages
     * (`ContentAddressData`) may name more: a home names every on store's home, by language and country (content.md
     * §1.14, Q21 (a)), and then those are its alternates.
     */
    address?: ShopAddressData & { alternates?: ContentAlternateData[] };
    /** Content's pages: the description and share image search engines and shared links read (content.md §1.14). */
    head?: ContentHeadData;
    /** The page's bands bring their own box, so their grounds reach the screen's edges (the home, shop-design.md §2.1). */
    bands?: boolean;
    /**
     * A phone's compact top bar (shop-design.md §2.6, §5.4, C12): back to `back`, the page's title, and the search behind
     * an icon, in place of the menu, the logo and the search row. From 640 px up the header is the same as everywhere.
     */
    compact?: { back: string };
    /** A product's page hides the phone's tab bar: its buy bar takes the bottom (shop-design.md §6.6). */
    tabBar?: boolean;
};

type PopupView = ComponentType<{ popup: NonNullable<ContentFrame['popup']> }>;

export function StorefrontLayout({ title, children, address, head, bands = false, compact, tabBar = true }: Props) {
    const page = usePage<SharedProps>();
    const { shop, shopper, shopperLines, staffView, locale, frame, theme } = page.props;
    const link = useLink();
    const catalog = frame?.catalog;
    const bell = frame?.ops;
    const content = frame?.content;
    const words = content?.words ?? {};
    const inStore = shop !== null && shop !== undefined;
    // The newsletter box (ops.md §4.15 O11) loads once the page has drawn, so no shop page carries it on first
    // paint (the page budget; a lazy part drawn by the server would break its render).
    const newsletter = !inStore || staffView ? null : (bell?.newsletter ?? null);
    // Once drawn in this tab it stays: each page draws its own layout, and the box would go and come back on every visit.
    const [drawn, setDrawn] = useState(boxDrawn);
    const [drawer, setDrawer] = useState(false);
    // The compact bar's search, opened by its icon.
    const [searching, setSearching] = useState(false);
    const t = useTranslator();
    const [Popup, setPopup] = useState<PopupView | null>(null);
    useEffect(() => {
        boxDrawn = true;
        setDrawn(true);
    }, []);

    // A live theme's artwork (content.md §1.8): the server's custom properties, in the shell on a full load. A move to
    // another store or language replaces them (part 11 is written per direction); text, never markup. The shop's only:
    // the panel never wears a theme.
    const style = theme.style ?? '';
    useEffect(() => {
        let element = document.getElementById('tw-campaign');

        if (element === null && style !== '') {
            element = document.head.appendChild(document.createElement('style'));
            element.id = 'tw-campaign';
        }

        if (element !== null && element.textContent !== style) {
            element.textContent = style;
        }
    }, [style]);

    // The pop-up (content.md §1.10): its own file, loaded in the browser once a page carries one; it keeps the rest.
    const shown = content?.popup ?? null;
    useEffect(() => {
        if (shown !== null) {
            void import('@/components/content/ShopPopup').then((loaded) => setPopup(() => loaded.default));
        }
    }, [shown]);

    const home = inStore ? `/${shop.code}/${locale}` : '/';
    // The page itself and its other language - or every version a Content page names (a home: each on store's, Q21 (a)).
    const alternates: ContentAlternateData[] = address?.alternates?.length
        ? address.alternates
        : address
          ? [{ hrefLang: locale, href: address.canonical }, ...(address.alternate ? [{ hrefLang: address.alternateLocale ?? '', href: address.alternate }] : [])]
          : [];
    const favourites = inStore && !staffView && words.favourites ? (
        <Button asChild variant="ghost" size="icon" className="size-10 sm:size-[42px]">
            <Link href={link('storefront.feedback.wishlist')} aria-label={words.favourites} data-test="header-favourites">
                <Heart aria-hidden="true" />
            </Link>
        </Button>
    ) : null;

    return (
        <>
            <Head title={title}>
                {address ? <link rel="canonical" href={address.canonical} /> : null}
                {alternates.map((alternate) => (
                    <link key={alternate.hrefLang} rel="alternate" hrefLang={alternate.hrefLang} href={alternate.href} />
                ))}
                {head?.description ? <meta name="description" content={head.description} /> : null}
                {head ? <meta property="og:title" content={head.title} /> : null}
                {head?.description ? <meta property="og:description" content={head.description} /> : null}
                {head?.image ? <meta property="og:image" content={head.image} /> : null}
                {address?.noindex || content?.preview ? <meta name="robots" content="noindex" /> : null}
            </Head>

            {/* The page's ground: the theme's edges where it has some (`--tw-backdrop`, a whole background value). On a
                phone the tab bar's room is kept at the bottom. */}
            <div className={`flex min-h-screen flex-col ${tabBar ? 'pb-[calc(3.5rem+env(safe-area-inset-bottom))]' : 'pb-[calc(4.5rem+env(safe-area-inset-bottom))]'} text-ink [background:var(--tw-backdrop)] sm:pb-0`}>
                {/* The announcement alone, centred; with none, no bar (flags-2026-11.md S1.1). */}
                {inStore && content?.bar ? <TopBar bar={content.bar} store={shop.code} locale={locale} /> : null}

                <header className="sticky top-0 z-40 border-b border-line bg-surface">
                    <div className="shop-box flex flex-wrap items-center gap-x-1 max-sm:px-2! sm:h-[78px] sm:flex-nowrap sm:gap-4 lg:gap-9" data-test="header-row">
                        {/* The menu: the drawer on a phone and a tablet (Catalog's category row takes over from 768). */}
                        {inStore ? (
                            <Button
                                variant="ghost"
                                size="icon"
                                className={`size-10 md:hidden ${compact ? 'max-sm:hidden' : ''}`}
                                aria-label={catalog?.words.open_menu ?? words.menu}
                                data-test="category-sheet-open"
                                onPointerEnter={() => void import('@/components/content/ShopDrawer')}
                                onClick={() => setDrawer(true)}
                            >
                                <Menu aria-hidden="true" />
                            </Button>
                        ) : null}
                        {compact ? (
                            <>
                                <Button asChild variant="ghost" size="icon" className="size-10 sm:hidden">
                                    <Link href={compact.back} aria-label={t('ui.back')} data-test="compact-back">
                                        <ChevronLeft aria-hidden="true" className="rtl:rotate-180" />
                                    </Link>
                                </Button>
                                <span className="flex h-13 min-w-0 flex-1 items-center truncate text-label-16 font-semibold text-ink sm:hidden" data-test="compact-title">
                                    {title}
                                </span>
                                {catalog ? (
                                    <Button variant="ghost" size="icon" className="size-10 sm:hidden" aria-label={catalog.words.search_placeholder} aria-expanded={searching} onClick={() => setSearching(!searching)} data-test="compact-search">
                                        <Search aria-hidden="true" />
                                    </Button>
                                ) : null}
                            </>
                        ) : null}
                        <Link href={home} className={`flex h-13 min-w-0 flex-1 items-center gap-2 sm:h-auto sm:flex-none sm:gap-3 ${compact ? 'max-sm:hidden' : ''}`} data-test="header-logo">
                            <Logo tone="auto" decorative className="sm:size-[46px]" />
                            {/* The design's wordmark (H8): the name in Latin capitals, the Arabic under it. */}
                            <span className="grid leading-none" lang="en">
                                <span className="text-label-14 font-semibold tracking-[0.14em] text-ink sm:text-label-16">TOUCHWOOD</span>
                                <span lang="ar" className="mt-1 text-label-12 text-ink-muted">تاتش وود</span>
                            </span>
                        </Link>

                        {/* The search box, between the logo and the shopper's own things; on a phone, its own row under them. */}
                        {catalog ? (
                            <div className={`order-last w-full px-1 pb-2 sm:order-none sm:w-auto sm:min-w-0 sm:flex-1 sm:px-0 sm:pb-0 ${compact && !searching ? 'max-sm:hidden' : ''}`} data-test="search-row">
                                <SearchBox words={catalog.words} />
                            </div>
                        ) : null}

                        <div className="flex items-center gap-1 sm:ms-auto sm:gap-2">
                            {/* A phone's country (owner, 2026-11-27, option A): a chip beside the heart, its list the phone's own;
                                from 640 px up the header's own switch, the drawer keeps its own too. */}
                            {inStore && !compact && drawn && shop.available.length > 1 ? (
                                <Suspense fallback={null}>
                                    <CountryChip shop={shop} locale={locale} />
                                </Suspense>
                            ) : null}
                            {inStore ? (
                                <span className="hidden items-center gap-1 sm:flex">
                                    <CountrySwitch shop={shop} locale={locale} />
                                    {/* The other language beside the country (flags-2026-11.md S1.2, F2); the drawer keeps its own. */}
                                    <LanguageSwitch shop={shop} locale={locale} url={page.url} alternate={address?.alternate ?? null} />
                                </span>
                            ) : null}
                            {/* Ops' part of the frame (ops.md §4.15 O2): the bell, before the cart, for a signed-in customer only. */}
                            {!inStore || staffView || bell === undefined || bell.unread === null || bell.words === null ? null : (
                                <Bell base={`/${shop.code}/${locale}/account/notifications`} unread={bell.unread} words={bell.words} />
                            )}
                            {favourites}
                            {/* Sales's part of the frame (sales.md §4.1 S3): the cart, beside the account's; the tab bar's on a phone. */}
                            {!inStore || staffView || !frame?.sales ? null : (
                                <span className="hidden sm:inline-flex">
                                    <CartButton shop={shop.code} locale={locale} sales={frame.sales} test="header-cart" />
                                </span>
                            )}
                            {!inStore ? null : (
                                <span className="hidden sm:inline-flex">{staffView ? <StaffViewMenu staffView={staffView} shopCode={shop.code} /> : <Shopper shopper={shopper ?? null} themeWord={words.theme ?? ''} />}</span>
                            )}
                        </div>
                    </div>
                    {catalog ? <CategoryMenuBar menu={catalog.menu} words={catalog.words} wholesale={catalog.wholesale} /> : null}
                </header>

                {staffView ? <StaffViewLine /> : null}
                {inStore && content?.preview ? <PreviewLine name={content.preview} words={words} store={shop.code} locale={locale} /> : null}
                <ShopperLines lines={shopperLines ?? []} />

                <main className={bands ? 'w-full flex-1 pb-6 sm:pb-8' : 'shop-box flex-1 py-6 sm:py-8'}>{children}</main>

                {/* The design's dark footer, in the sidebar's colours in both modes (shop-design.md §2.5). */}
                <footer className="bg-sidebar text-sidebar-ink" data-test="shop-footer">
                    {/* The theme's footer artwork at its top edge (content.md §1.8 part 10). */}
                    {content?.theme?.footerArt ? <div aria-hidden="true" className="h-16 bg-cover bg-center [background-image:var(--tw-theme-footer)] sm:h-24" data-test="footer-art" /> : null}
                    {/* Ops' part of the frame (ops.md §4.15 O11): the newsletter box, for a guest only. */}
                    {drawn && newsletter ? (
                        <Suspense fallback={null}>
                            {/* The newsletter as a quiet line at the footer's top, no card (owner's shape D, 2026-11-25):
                                a copper mail mark, the words, the box and a copper button, a hairline under it. */}
                            <div className="shop-box">
                                <div className="border-b border-sidebar-line py-8" data-test="newsletter-card">
                                    <NewsletterBox words={newsletter} />
                                </div>
                            </div>
                        </Suspense>
                    ) : null}
                    {inStore && content?.footer ? <StoreFooter footer={content.footer} store={shop.code} locale={locale} words={words} /> : null}
                    {/* The theme switch left the footer for the account menu and the drawer (flags-2026-11.md S1.3). */}
                    {/* The bottom line: the copyright, and beside it a link to each of the store's published policies in the
                        kinds' order (flags-2026-11.md S3.5, F19); none when it has none. They wrap under it on a phone. */}
                    <div className="shop-box flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-sidebar-line py-6">
                        <span className="text-label-12 text-sidebar-ink-muted">{content?.footer?.copyright ?? 'TouchWood'}</span>
                        {inStore && (content?.policies ?? []).length > 0 ? (
                            <nav aria-label={words.policies} data-test="footer-policies">
                                <ul className="flex flex-wrap gap-x-4 gap-y-1">
                                    {(content?.policies ?? []).map((policy) => (
                                        <li key={policy.slug}>
                                            <Link href={`/${shop.code}/${locale}/policies/${encodeURIComponent(policy.slug)}`} className="inline-flex min-h-6 items-center text-label-12 text-sidebar-ink-muted hover:text-sidebar-ink hover:underline">
                                                {policy.title}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                        ) : null}
                    </div>
                </footer>

                {/* The phone's tab bar, once the page is in the browser: its room is kept above from the first paint. */}
                {inStore && drawn && tabBar ? (
                    <Suspense fallback={null}>
                        <TabBar House={House} shop={shop.code} locale={locale} url={page.url} words={words} sales={staffView ? undefined : frame?.sales} staffView={Boolean(staffView)} />
                    </Suspense>
                ) : null}
            </div>

            {/* The phone's drawer (the owner's option C3): the Sheet here, what it holds loaded when it first opens. */}
            {inStore ? (
                <Sheet open={drawer} onOpenChange={setDrawer}>
                    {/* "left" is the page's start side: the Sheet is written in logical sides (start-0), the right in Arabic. */}
                    <SheetContent side="left" className="flex w-[min(320px,86vw)] flex-col gap-0 p-0" data-test="category-sheet">
                        <SheetHeader className="flex-row items-center gap-3 border-b border-line p-3 pe-12">
                            <Logo tone="auto" decorative />
                            <SheetTitle className="text-heading-16">TouchWood</SheetTitle>
                            <SheetDescription className="sr-only">{words.menu}</SheetDescription>
                        </SheetHeader>
                        <Suspense fallback={null}>
                            <ShopDrawer alternate={address?.alternate ?? null} />
                        </Suspense>
                    </SheetContent>
                </Sheet>
            ) : null}
            {Popup !== null && shown !== null ? <Popup popup={shown} /> : null}
            <SyncDocument />
            <Toasts />
        </>
    );
}

/**
 * The top bar (shop-design.md §2.2; content.md §1.5; flags-2026-11.md S1.1): 38 px, **the announcement alone,
 * centred** - one line, cut with an ellipsis, its link's page holding the whole text. Track Order and the language
 * left it (the owner, 2026-11-18: the language sits beside the country picker). Drawn only with an announcement. A
 * live theme's strip is its ground (§1.8 part 1). **The logo's navy in both modes** (F1), white words: the brand token
 * turns light blue in dark mode, and the sidebar's navy read as near-black.
 */
function TopBar({ bar, store, locale }: { bar: NonNullable<ContentFrame['bar']>; store: string; locale: string }) {
    return (
        <div className={`bg-logo-navy text-white ${bar.art ? 'bg-cover bg-center [background-image:var(--tw-theme-bar)]' : ''}`} data-test="top-bar">
            <div className="shop-box flex h-[38px] items-center justify-center text-copy-13">
                <p className="min-w-0 truncate text-center" data-test="announcement-bar">
                    <ContentLink link={bar.link} store={store} locale={locale} className="min-w-0 truncate underline-offset-4 hover:underline">
                        <span className="block truncate">{bar.text}</span>
                    </ContentLink>
                </p>
            </div>
        </div>
    );
}

/** The cart's button, with its pieces (R1-10): in the header from a tablet up - the design's filled navy button with its name (§2.3) - in the tab bar on a phone. */
function CartButton({ shop, locale, sales, test }: { shop: string; locale: string; sales: NonNullable<NonNullable<SharedProps['frame']>['sales']>; test: string }) {
    const label = sales.products > 0 ? `${sales.words.cart}${locale === 'ar' ? '، ' : ', '}${(sales.words.products[new Intl.PluralRules(intlLocale(locale)).select(sales.products)] ?? '').replace(':count', figure(locale, sales.products))}` : sales.words.cart;

    return (
        <Button asChild className="h-[42px] gap-2 px-4">
            <Link href={`/${shop}/${locale}/cart`} aria-label={label} className="relative" data-test={test}>
                <ShoppingCart aria-hidden="true" />
                <span aria-hidden="true">{sales.words.cart}</span>
                {sales.products > 0 ? (
                    <Badge className="tw-figure h-5 min-w-5 rounded-full bg-surface px-1 text-[11px] leading-none text-ink" aria-hidden="true" data-test={`${test}-count`}>
                        {sales.products > 99 ? '99+' : figure(locale, sales.products)}
                    </Badge>
                ) : null}
            </Link>
        </Button>
    );
}

/**
 * The store's footer (content.md §1.5): a line about the store, phone, email and address, up to 3 columns of links,
 * per language. On a phone the design's compact footer: the links as rows, the columns' names left out.
 */
function StoreFooter({ footer, store, locale, words }: { footer: NonNullable<ContentFrame['footer']>; store: string; locale: string; words: Record<string, string> }) {
    const contact = [footer.phone, footer.email, footer.address].filter((line): line is string => line !== null && line !== '');
    // A link to one of Content's lists with nothing published would answer 404 or an empty page: not drawn (S1.4, F4).
    const hidden = new Set(footer.hidden ?? []);
    const columns = footer.columns
        .map((column) => ({ ...column, links: column.links.filter((item) => !hidden.has(item.link.replace(/\/+$/, ''))) }))
        .filter((column) => column.links.length > 0);
    // The phone and the email open the call or the mail app (flags-2026-11.md S1.4).
    const href = (line: string): string | null => (line === footer.phone ? `tel:${line.replace(/[\s-]/g, '')}` : line === footer.email ? `mailto:${line}` : null);

    return (
        <div className="shop-box grid gap-6 py-8 sm:grid-cols-[1.4fr_repeat(3,1fr)] sm:gap-10 sm:py-12" data-test="store-footer">
            <div className="grid content-start gap-3">
                {/* The design's footer opens with the logo (§2.5). */}
                <span className="flex items-center gap-3" aria-hidden="true">
                    <Logo tone="navy" decorative className="size-10" />
                    <span className="text-label-14 font-semibold tracking-[0.14em]">TOUCHWOOD</span>
                </span>
                {footer.about ? <p className="max-w-[34ch] text-copy-14 text-sidebar-ink-muted">{footer.about}</p> : null}
                {contact.map((line) => {
                    const to = href(line);

                    return (
                        <bdi key={line} dir={line === footer.address ? undefined : 'ltr'} className="block text-copy-13 text-sidebar-ink-muted">
                            {to === null ? line : (
                                <a href={to} className="hover:text-sidebar-ink hover:underline" data-test={line === footer.phone ? 'footer-phone' : 'footer-email'}>
                                    {line}
                                </a>
                            )}
                        </bdi>
                    );
                })}
            </div>
            {columns.map((column, index) => (
                <nav key={index} aria-label={column.title || words.footer_links} className="grid content-start gap-2">
                    <span className="hidden text-label-13 font-semibold text-sidebar-ink sm:block">{column.title}</span>
                    <ul className="grid sm:gap-2">
                        {column.links.map((item) => (
                            <li key={item.link + item.text}>
                                <ContentLink link={item.link} store={store} locale={locale} className="flex min-h-10 items-center text-copy-14 text-sidebar-ink-muted hover:text-sidebar-ink sm:min-h-0">
                                    {item.text}
                                </ContentLink>
                            </li>
                        ))}
                    </ul>
                </nav>
            ))}
        </div>
    );
}

/** A theme being previewed here (content.md W5b): its name, and Stop Preview, which forgets this browser's cookie. */
function PreviewLine({ name, words, store, locale }: { name: string; words: Record<string, string>; store: string; locale: string }) {
    return (
        <div className="border-b border-line bg-accent-soft text-ink" data-test="preview-line">
            <p className="shop-box flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-copy-14">
                <span>{(words.previewing ?? ':name').replace(':name', name)}</span>
                <button type="button" className="font-medium underline underline-offset-4" data-test="stop-preview" onClick={() => router.post(`/${store}/${locale}/theme-preview/stop`)}>
                    {words.stop_preview}
                </button>
            </p>
        </div>
    );
}

/**
 * Whoever is in the shop: the way in, or their name, which opens their menu (frontend.md §3.6;
 * owner's #4, 2026-10-02: shadcn's user menu, the nav-user pattern of `sidebar-07`).
 *
 * An address that has not been confirmed is said here rather than left for the moment somebody
 * tries to order: until it is, they may look around and fill a basket and no more (F4).
 *
 * A sign-out is a post, because it changes something; destructive, so it is last (Geist's Menu).
 */
function Shopper({ shopper, themeWord }: { shopper: SharedProps['shopper']; themeWord: string }) {
    const { theme } = usePage<SharedProps>().props;
    const t = useTranslator();
    const link = useLink();

    if (shopper === null || shopper === undefined) {
        return (
            <Button variant="outline" size="sm" asChild>
                <Link href={link('storefront.sign-in')}>{t('access::auth.sign_in')}</Link>
            </Button>
        );
    }

    return (
        <span className="flex items-center gap-2">
            {shopper.emailVerified ? null : (
                // A badge says it in words, never by colour alone (Geist's Badge).
                <Badge asChild variant="outline" className="border-transparent bg-warn-soft text-warn">
                    <Link href={link('storefront.verify-email')} data-test="verify-email">
                        {t('access::auth.verify_pending')}
                    </Link>
                </Badge>
            )}

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm" data-test="shopper-menu">
                        {shopper.name}
                        <ChevronDown aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-48 rounded-lg">
                    <DropdownMenuLabel className="truncate font-normal text-muted-foreground">{shopper.name}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild>
                        <Link href={link('storefront.account')} data-test="my-account">
                            <UserRound />
                            {t('access::auth.my_account')}
                        </Link>
                    </DropdownMenuItem>
                    {/* The shop's one theme switch on a computer (flags-2026-11.md S1.3; Geist: "once per app, in the
                        footer or settings"): System, Light, Dark as menu items, reachable from the keyboard, the
                        panel's person menu's own (NavUser). */}
                    <DropdownMenuSeparator />
                    <span className="block px-2 pt-1 text-label-12 text-ink-muted" data-test="menu-theme">{themeWord}</span>
                    <ThemeMenuSwitcher value={theme.choice} onChange={(choice) => choosePreference('theme', choice, '/preferences')} />
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" data-test="sign-out" onSelect={() => router.post(link('storefront.account.sign-out'))}>
                        <LogOut />
                        {t('access::auth.sign_out')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </span>
    );
}

/**
 * A staff member looking at the shop from the panel (access.md §1.11): their name, which opens the
 * way back to the panel and the way out of the view. Leaving is a post, last; it is not destructive -
 * the shop simply shows whoever its own session holds again.
 *
 * The panel is a plain link, not an Inertia visit: it is the other area, with its own session and
 * its own frame. Leaving posts to the top level - an off store's pages only read during a staff
 * view - naming the store, which it goes back to if that store is on.
 */
function StaffViewMenu({ staffView, shopCode }: { staffView: NonNullable<SharedProps['staffView']>; shopCode: string }) {
    const t = useTranslator();
    const link = useLink();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" data-test="staff-view-menu">
                    {staffView.name}
                    <ChevronDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48 rounded-lg">
                <DropdownMenuLabel className="truncate font-normal text-muted-foreground">{staffView.name}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <a href="/admin" data-test="staff-view-panel">
                        <LayoutDashboard />
                        {t('admin.panel')}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem data-test="staff-view-leave" onSelect={() => router.post(link('storefront.staff-view.leave'), { store: shopCode })}>
                    <LogOut />
                    {t('admin.staff_view.leave')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The line under the header while a staff view holds (frontend.md §2.3): what this is, and the way
 * back - in the shopper lines' place and look, so nothing new is drawn.
 */
function StaffViewLine() {
    const t = useTranslator();

    return (
        <div className="border-b border-line bg-brand-soft text-ink" data-test="staff-view-line">
            <p className="shop-box flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-copy-14">
                <span>{t('admin.staff_view.line')}</span>
                <a href="/admin" className="font-medium underline underline-offset-4" data-test="staff-view-back">
                    {t('admin.staff_view.back')}
                </a>
            </p>
        </div>
    );
}

/**
 * What other modules have to tell the customer signed in, on every page of the shop (access.md
 * amendment 50): B2B saying why a company account cannot order yet. Each line is a link to where it
 * can be dealt with. The words are the module's, already in the page's language.
 */
function ShopperLines({ lines }: { lines: ShopperLine[] }) {
    const link = useLink();

    if (lines.length === 0) {
        return null;
    }

    // The background says the tone; the words stay in the page's own ink, which reads on every one
    // of them — warn's own colour on its soft background did not (3.25:1, the review of step 6).
    const tones: Record<ShopperLine['tone'], string> = {
        info: 'bg-brand-soft text-ink',
        warn: 'bg-warn-soft text-ink',
        bad: 'bg-bad-soft text-ink',
    };

    return (
        <div className="grid">
            {lines.map((line) => (
                <Link
                    key={line.routeName + line.text}
                    href={link(line.routeName)}
                    data-test="shopper-line"
                    data-tone={line.tone}
                    className={['block border-b border-line', tones[line.tone]].join(' ')}
                >
                    <span className="shop-box block py-2 text-copy-14 hover:underline">{line.text}</span>
                </Link>
            ))}
        </div>
    );
}

/**
 * The country somebody is shopping in. Changing it is a different shop: different prices, stock and
 * delivery, so it goes to that store's home rather than to the same page under another store.
 */
function CountrySwitch({ shop, locale }: { shop: NonNullable<SharedProps['shop']>; locale: string }) {
    const t = useTranslator();

    return (
        <NativeSelect
            id="country-switch"
            size="sm"
            data-test="country-switch"
            value={shop.code}
            aria-label={t('platform::stores.choose_title')}
            onChange={(event) => router.visit(`/${event.target.value}/${locale}`)}
            className="w-40"
        >
            {/* An off store is listed only during a staff view that covers it, and says so. */}
            {shop.available.map((store) => (
                <NativeSelectOption key={store.code} value={store.code}>
                    {store.isActive ? store.name : `${store.name} (${t('admin.store.off')})`}
                </NativeSelectOption>
            ))}
        </NativeSelect>
    );
}

/**
 * The same page in the other language, which is the same address with one segment changed - so
 * somebody reading a page keeps their place instead of being sent back to the front. A catalogue
 * page's slug is its language's (catalog.md §4.5, P19): it says where the other language's is.
 */
function LanguageSwitch({
    shop,
    locale,
    url,
    alternate,
}: {
    shop: NonNullable<SharedProps['shop']>;
    locale: string;
    url: string;
    alternate: string | null;
}) {
    return (
        <span className="flex items-center gap-1">
            {shop.languages
                .filter((language) => language !== locale)
                .map((language) => (
                    // The owner's #3 (2026-10-02): one button showing the other language's name; beside the country
                    // picker since flags-2026-11.md S1.2 (F2), a ghost button its height.
                    <Button key={language} variant="ghost" size="sm" asChild className="text-label-14">
                        <Link
                            data-test={`language-${language}`}
                            // The language is the second segment: /sa/ar/account becomes /sa/en/account.
                            href={alternate ?? url.replace(`/${shop.code}/${locale}`, `/${shop.code}/${language}`)}
                            // Written in the language being offered, never translated: somebody who
                            // cannot read the current one must still recognise it.
                            lang={language}
                        >
                            {language === 'ar' ? 'العربية' : 'English'}
                        </Link>
                    </Button>
                ))}
        </span>
    );
}
