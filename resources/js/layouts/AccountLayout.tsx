import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from 'cn';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useLink } from '@/lib/routes';
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
| On a phone the list sits above the panel rather than beside it; a fourteen-rem column next to a
| form at 375px leaves room for neither.
|
| The panel is no card of its own: each section inside it is one (Geist's Fieldset, shadcn's Card),
| and a card inside a card says the page is built wrong (Geist's Materials).
*/

type Props = {
    title: string;
    subtitle?: string;
    children: ReactNode;
} & (
    | {
          /** On the account page: the tab open, and how to open another. */
          tab: string;
          onTab: (key: string) => void;
          page?: never;
      }
    | {
          /** On another module's page: its key in the list, e.g. "b2b.company". */
          page: string;
          tab?: never;
          onTab?: never;
      }
);

/** A link in the list, drawn as the line tabs under it are: muted, the current one in ink with its bar. */
function linkClass(current: boolean): string {
    return cn(
        'relative flex h-9 w-full items-center rounded-md px-2 text-label-14 font-medium transition-colors',
        'after:absolute after:inset-y-0 after:-end-1 after:w-0.5 after:bg-ink',
        current ? 'text-ink after:opacity-100' : 'text-ink-muted after:opacity-0 hover:text-ink',
    );
}

export function AccountLayout({ title, subtitle, children, ...where }: Props) {
    const { accountMenu } = usePage<SharedProps>().props;
    const link = useLink();
    const tabs = accountMenu?.tabs ?? [];
    const pages = accountMenu?.pages ?? [];
    // Taken out once, so the callbacks below keep what the check found.
    const onTab = where.onTab;

    const heading = (
        <div className="grid gap-1">
            <h1 className="text-heading-24 text-ink">{title}</h1>
            {subtitle ? <p className="text-copy-14 text-ink-muted">{subtitle}</p> : null}
        </div>
    );

    const pageLinks = pages.map((page) => (
        <Link
            key={page.key}
            href={link(page.routeName)}
            data-test={`account-page-${page.key}`}
            aria-current={where.page === page.key ? 'page' : undefined}
            className={linkClass(where.page === page.key)}
        >
            {page.label}
        </Link>
    ));

    if (onTab === undefined) {
        // Another module's page: the account's headings lead back to their tabs, and the page draws
        // its own cards (the company page has two columns).
        return (
            <StorefrontLayout title={title}>
                <div className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                    <nav aria-label={title} className="grid content-start gap-1">
                        {pageLinks}
                        {tabs.map((tab) => (
                            <Link key={tab.key} href={link('storefront.account', { tab: tab.key })} data-test={`tab-${tab.key}`} className={linkClass(false)}>
                                {tab.label}
                            </Link>
                        ))}
                    </nav>

                    <section className="grid content-start gap-4">
                        {heading}
                        {children}
                    </section>
                </div>
            </StorefrontLayout>
        );
    }

    return (
        <StorefrontLayout title={title}>
            <Tabs orientation="vertical" value={where.tab} onValueChange={onTab} className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                <nav aria-label={title} className="grid content-start gap-1">
                    {pageLinks}
                    <TabsList variant="line" aria-label={title} className="w-full items-stretch">
                        {tabs.map((tab) => (
                            <TabsTrigger key={tab.key} value={tab.key} data-test={`tab-${tab.key}`} className="h-9 flex-none px-2 text-label-14">
                                {tab.label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </nav>

                <TabsContent value={where.tab} className="grid content-start gap-5">
                    {heading}
                    {children}
                </TabsContent>
            </Tabs>
        </StorefrontLayout>
    );
}
