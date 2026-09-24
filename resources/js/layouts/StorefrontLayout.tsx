import { type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { ThemeToggle } from '@/components/Preferences';
import { Logo } from '@/components/Logo';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The shop's frame (frontend.md §2.3).
|
| The header carries the logo, the country, the language and the theme, and either "Sign in" or the
| name of whoever is signed in. Search and the basket belong to Catalog and Sales and are not here.
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
    const { shop, shopper, locale } = page.props;

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
                            <Logo className="text-brand" />
                            <span className="text-sm font-semibold">TouchWood</span>
                        </Link>

                        <div className="flex flex-wrap items-center gap-3">
                            {shop === null || shop === undefined ? null : (
                                <>
                                    <CountrySwitch shop={shop} locale={locale} />
                                    <LanguageSwitch shop={shop} locale={locale} url={page.url} />
                                </>
                            )}

                            <ThemeToggle />

                            {/* A shopper's name, and behind it their own account - the only thing
                                there until Sales gives them orders to look at.

                                Nothing at all when nobody is signed in: the way in is a page that
                                does not exist yet, and a header that offers a door to a 404 is
                                worse than one that offers none. It arrives with the account
                                screens (frontend.md 3.6, F3-F5). */}
                            {shop !== null && shop !== undefined && shopper !== null && shopper !== undefined ? (
                                <Link
                                    href={`/${shop.code}/${locale}/account`}
                                    className="text-sm text-ink hover:text-brand"
                                >
                                    {shopper.name}
                                </Link>
                            ) : null}
                        </div>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{children}</main>

                <footer className="border-t border-line bg-surface">
                    <div className="mx-auto w-full max-w-6xl px-4 py-6 text-xs text-ink-muted">TouchWood</div>
                </footer>
            </div>

            <SyncDocument />
            <Toasts />
        </>
    );
}

/**
 * The country somebody is shopping in. Changing it is a different shop: different prices, stock and
 * delivery, so it goes to that store's home rather than to the same page under another store.
 */
function CountrySwitch({ shop, locale }: { shop: NonNullable<SharedProps['shop']>; locale: string }) {
    const t = useTranslator();

    return (
        <select
            data-test="country-switch"
            value={shop.code}
            aria-label={t('platform::stores.choose_title')}
            onChange={(event) => router.visit(`/${event.target.value}/${locale}`)}
            className="h-8 rounded-md border border-line bg-surface px-2 text-xs text-ink"
        >
            {shop.available.map((store) => (
                <option key={store.code} value={store.code}>
                    {store.name}
                </option>
            ))}
        </select>
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
                    <Link
                        key={language}
                        data-test={`language-${language}`}
                        // The language is the second segment: /sa/ar/account becomes /sa/en/account.
                        href={url.replace(`/${shop.code}/${locale}`, `/${shop.code}/${language}`)}
                        // Written in the language being offered, never translated: somebody who
                        // cannot read the current one must still recognise it.
                        lang={language}
                        className="rounded-md border border-line px-2 py-1 text-xs text-ink-muted hover:border-brand hover:text-brand"
                    >
                        {language === 'ar' ? 'العربية' : 'English'}
                    </Link>
                ))}
        </span>
    );
}
