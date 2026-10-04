import { type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, UserRound } from 'lucide-react';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { ThemeToggle } from '@/components/Preferences';
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
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps, ShopperLine } from '@/types/page';

/*
| The shop's frame (frontend.md §2.3), on shadcn's code with Geist's rules (§1.11).
|
| The header carries the logo, the country, the language, and either "Sign in" or the name of
| whoever is signed in, which opens their menu - My Account and Sign Out (owner's #4, 2026-10-02).
| Search and the basket belong to Catalog and Sales and are not here. The theme switch is in the
| footer, once for the whole shop (Geist: "once per app, in the footer or settings").
|
| **The country and the language are both in the address**, not in a cookie the page reads: a shop
| page lives at /{store}/{locale}, so switching either is a link to the same page written the other
| way. That keeps a link somebody sends to a friend meaning what it said, and lets a search engine
| see three stores in two languages rather than one page that changes under it.
|
| Arabic mirrors the whole frame, because the page's dir is set on <html> by the server.
*/

type Props = {
    title: string;
    children: ReactNode;
};

export function StorefrontLayout({ title, children }: Props) {
    const page = usePage<SharedProps>();
    const { shop, shopper, shopperLines, locale } = page.props;

    return (
        <>
            <Head title={title} />

            {/* The backdrop is a campaign's to decide: a colour today, a photograph or several
                layered things tomorrow, without touching this file (owner, 2026-09-22). */}
            <div className="flex min-h-screen flex-col bg-backdrop text-ink">
                <header className="border-b border-line bg-surface">
                    <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4">
                        <Link
                            href={shop === null || shop === undefined ? '/' : `/${shop.code}/${locale}`}
                            className="flex items-center gap-3"
                        >
                            <Logo tone="auto" />
                            <span className="text-heading-16">TouchWood</span>
                        </Link>

                        <div className="flex flex-wrap items-center gap-3">
                            {shop === null || shop === undefined ? null : (
                                <>
                                    <CountrySwitch shop={shop} locale={locale} />
                                    <LanguageSwitch shop={shop} locale={locale} url={page.url} />
                                </>
                            )}

                            {/* Only under a store: the country page has none, and every address in
                                the shop is written inside one. */}
                            {shop === null || shop === undefined ? null : (
                                <Shopper shopper={shopper ?? null} />
                            )}
                        </div>
                    </div>
                </header>

                <ShopperLines lines={shopperLines ?? []} />

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{children}</main>

                <footer className="border-t border-line bg-surface">
                    <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-6">
                        <span className="text-label-12 text-muted-foreground">TouchWood</span>
                        {/* The shop's one theme switch, small, as Geist sizes it for a footer. */}
                        <ThemeToggle to="/preferences" size="small" />
                    </div>
                </footer>
            </div>

            <SyncDocument />
            <Toasts />
        </>
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
function Shopper({ shopper }: { shopper: SharedProps['shopper'] }) {
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
                    <span className="mx-auto block w-full max-w-6xl px-4 py-2 text-copy-14 hover:underline">{line.text}</span>
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
            {shop.available.map((store) => (
                <NativeSelectOption key={store.code} value={store.code}>
                    {store.name}
                </NativeSelectOption>
            ))}
        </NativeSelect>
    );
}

/**
 * The same page in the other language, which is the same address with one segment changed - so
 * somebody reading a page keeps their place instead of being sent back to the front.
 */
function LanguageSwitch({
    shop,
    locale,
    url,
}: {
    shop: NonNullable<SharedProps['shop']>;
    locale: string;
    url: string;
}) {
    return (
        <span className="flex items-center gap-1">
            {shop.languages
                .filter((language) => language !== locale)
                .map((language) => (
                    // The owner's #3 (2026-10-02): one button showing the other language's name.
                    <Button key={language} variant="outline" size="sm" asChild>
                        <Link
                            data-test={`language-${language}`}
                            // The language is the second segment: /sa/ar/account becomes /sa/en/account.
                            href={url.replace(`/${shop.code}/${locale}`, `/${shop.code}/${language}`)}
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
