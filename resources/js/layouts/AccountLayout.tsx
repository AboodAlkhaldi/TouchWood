import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import type { SharedProps } from '@/types/page';

/*
| A customer's own pages, inside the shop (frontend.md §2, §3.6).
|
| It is the storefront's frame with a side list of the customer's pages, not a frame of its own: a
| person moving between the shop and their account must not feel they have left the site.
|
| The account's own headings are **tabs, not links** - the panel's account works the same way, and
| for the same reason: it is one person's account, it arrives in one payload, and pressing a heading
| should not cost a round trip. Which one is open is still written into the address when the server
| sends the page, so a form that saved comes back to the tab the person was on.
|
| **Other modules' pages sit above them, as links** (access.md amendment 50): B2B's company page for
| a company account. The list arrives with every shop page, so on one of those pages the account's
| own headings become links back to their tabs.
|
| On a phone the list sits above the panel rather than beside it; a fourteen-rem column next to a
| form at 375px leaves room for neither.
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

const ITEM = 'rounded-md px-3 py-2 text-start text-sm transition-colors';
const CURRENT = 'bg-brand-soft font-medium text-brand';
const OTHER = 'text-ink-muted hover:bg-surface-sunken';

export function AccountLayout({ title, subtitle, children, ...where }: Props) {
    const { accountMenu } = usePage<SharedProps>().props;
    const link = useLink();
    const tabs = accountMenu?.tabs ?? [];
    const pages = accountMenu?.pages ?? [];
    // Taken out once, so the callbacks below keep what the check found.
    const onTab = where.onTab;

    return (
        <StorefrontLayout title={title}>
            <div className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                <nav aria-label={title} className="grid content-start gap-1">
                    {pages.map((page) => (
                        <Link
                            key={page.key}
                            href={link(page.routeName)}
                            data-test={`account-page-${page.key}`}
                            aria-current={where.page === page.key ? 'page' : undefined}
                            className={[ITEM, where.page === page.key ? CURRENT : OTHER].join(' ')}
                        >
                            {page.label}
                        </Link>
                    ))}

                    {onTab === undefined ? (
                        // Another module's page: the account's headings lead back to their tabs.
                        tabs.map((tab) => (
                            <Link
                                key={tab.key}
                                href={link('storefront.account', { tab: tab.key })}
                                data-test={`tab-${tab.key}`}
                                className={[ITEM, OTHER].join(' ')}
                            >
                                {tab.label}
                            </Link>
                        ))
                    ) : (
                        <div className="grid gap-1" role="tablist" aria-orientation="vertical">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    role="tab"
                                    id={`tab-${tab.key}`}
                                    aria-selected={where.tab === tab.key}
                                    aria-controls={`panel-${tab.key}`}
                                    data-test={`tab-${tab.key}`}
                                    onClick={() => onTab(tab.key)}
                                    className={[ITEM, where.tab === tab.key ? CURRENT : OTHER].join(' ')}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    )}
                </nav>

                {onTab === undefined ? (
                    // Another module's page draws its own cards (the company page has two columns).
                    <section className="grid content-start gap-4">
                        <div className="grid gap-1">
                            <h1 className="text-lg font-semibold text-ink">{title}</h1>
                            {subtitle ? <p className="text-sm text-ink-muted">{subtitle}</p> : null}
                        </div>

                        {children}
                    </section>
                ) : (
                    <section
                        role="tabpanel"
                        id={`panel-${where.tab}`}
                        aria-labelledby={`tab-${where.tab}`}
                        className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card"
                    >
                        <div className="grid gap-1">
                            <h1 className="text-lg font-semibold text-ink">{title}</h1>
                            {subtitle ? <p className="text-sm text-ink-muted">{subtitle}</p> : null}
                        </div>

                        {children}
                    </section>
                )}
            </div>
        </StorefrontLayout>
    );
}
