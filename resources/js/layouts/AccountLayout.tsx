import { useState, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from 'cn';
import { ChevronDown } from 'lucide-react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| A customer's own pages, inside the shop (frontend.md §2, §3.6).
|
| It is the storefront's frame with a side list of the customer's pages, not a frame of its own: a
| person moving between the shop and their account must not feel they have left the site.
|
| The account's own headings are **shadcn's Tabs**, upright, in their `line` look (frontend.md
| §1.11): Radix gives the arrow keys, the roving focus and the roles a screen reader needs. The
| account arrives in one payload, so pressing a heading costs no round trip; the page writes the
| open one into the address, so a refresh or a shared link opens the same tab (Geist's Tabs).
|
| **Other modules' pages sit above them, as links** (access.md amendment 50): B2B's company page for
| a company account - a page of its own, not a tab of this one (Geist: a sub-menu for unrelated
| pages). The list arrives with every shop page, so on one of those pages the account's own
| headings become links back to their tabs, drawn the same way.
|
| This file is the frame of those other pages; the account page's own, with the Tabs, is
| AccountTabsLayout. Apart, so a page that draws no tabs does not download them (the 60 KB page
| budget, frontend.md §5: the company page went over it when the phone's menu came, 2026-10-29).
|
| On a phone the list sits above the panel rather than beside it; a fourteen-rem column next to a
| form at 375px leaves room for neither - and it is folded there, behind one button (AccountMenu).
|
| The panel is no card of its own: each section inside it is one (Geist's Fieldset, shadcn's Card),
| and a card inside a card says the page is built wrong (Geist's Materials).
*/

type Props = {
    title: string;
    subtitle?: string;
    /** The page's key in the list, e.g. "b2b.company". */
    page: string;
    children: ReactNode;
};

/** A link in the list, drawn as the line tabs under it are: muted, the current one in ink with its bar. */
export function accountLinkClass(current: boolean): string {
    return cn(
        'relative flex h-9 w-full items-center rounded-md px-2 text-label-14 font-medium transition-colors',
        'after:absolute after:inset-y-0 after:-end-1 after:w-0.5 after:bg-ink',
        current ? 'text-ink after:opacity-100' : 'text-ink-muted after:opacity-0 hover:text-ink',
    );
}

/**
 * The list, folded on a phone into shadcn's Collapsible, shut, so the page's own content is the first
 * thing a phone shows (owner, 2026-10-29, the phone audit: eleven links put an order below the first
 * screen). From tablet width it is always open and its button is gone.
 *
 * Its content is always in the page (`forceMount`) and hidden by the stylesheet only on a phone while
 * shut: the server's HTML is then right at any width, with nothing to flash or move once React wakes.
 */
export function AccountMenu({ label, open, onOpenChange, children }: { label: string; open: boolean; onOpenChange: (open: boolean) => void; children: ReactNode }) {
    const t = useTranslator();

    return (
        <Collapsible open={open} onOpenChange={onOpenChange} asChild>
            <nav aria-label={label} className="grid content-start gap-1">
                <CollapsibleTrigger asChild>
                    <Button type="button" variant="outline" className="group justify-between md:hidden" data-test="account-menu">
                        {t('access::auth.account_menu')}
                        <ChevronDown aria-hidden="true" className="transition-transform group-data-[state=open]:rotate-180" />
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent forceMount className="grid content-start gap-1 max-md:data-[state=closed]:hidden">
                    {children}
                </CollapsibleContent>
            </nav>
        </Collapsible>
    );
}

/** The page's title and the line under it, at the top of the panel. */
export function AccountHeading({ title, subtitle }: { title: string; subtitle?: string }) {
    return (
        <div className="grid gap-1">
            <h1 className="text-heading-24 text-ink">{title}</h1>
            {subtitle ? <p className="text-copy-14 text-ink-muted">{subtitle}</p> : null}
        </div>
    );
}

/** The other modules' pages, as links, the one being read marked. */
export function AccountPageLinks({ current }: { current?: string }) {
    const { accountMenu } = usePage<SharedProps>().props;
    const link = useLink();

    return (accountMenu?.pages ?? []).map((page) => (
        <Link
            key={page.key}
            href={link(page.routeName)}
            data-test={`account-page-${page.key}`}
            aria-current={current === page.key ? 'page' : undefined}
            className={accountLinkClass(current === page.key)}
        >
            {page.label}
        </Link>
    ));
}

export function AccountLayout({ title, subtitle, page, children }: Props) {
    const { accountMenu } = usePage<SharedProps>().props;
    const link = useLink();
    const [menuOpen, setMenuOpen] = useState(false);

    // Another module's page: the account's headings lead back to their tabs, and the page draws its own
    // cards (the company page has two columns).
    return (
        <StorefrontLayout title={title}>
            <div className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                <AccountMenu label={title} open={menuOpen} onOpenChange={setMenuOpen}>
                    <AccountPageLinks current={page} />
                    {(accountMenu?.tabs ?? []).map((tab) => (
                        <Link key={tab.key} href={link('storefront.account', { tab: tab.key })} data-test={`tab-${tab.key}`} className={accountLinkClass(false)}>
                            {tab.label}
                        </Link>
                    ))}
                </AccountMenu>

                <section className="grid content-start gap-4">
                    <AccountHeading title={title} subtitle={subtitle} />
                    {children}
                </section>
            </div>
        </StorefrontLayout>
    );
}
