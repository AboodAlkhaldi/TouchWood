import type { ReactNode } from 'react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';

/*
| A customer's own pages, inside the shop (frontend.md §2, §3.6).
|
| It is the storefront's frame with a side list of the customer's pages, not a frame of its own: a
| person moving between the shop and their account must not feel they have left the site.
|
| The side list is **tabs, not links** - the panel's account works the same way, and for the same
| reason: it is one person's account, it arrives in one payload, and pressing a heading should not
| cost a round trip. Which one is open is still written into the address when the server sends the
| page, so a form that saved comes back to the tab the person was on.
|
| On a phone the list sits above the panel rather than beside it; a fourteen-rem column next to a
| form at 375px leaves room for neither.
*/

type Section<T extends string> = {
    key: T;
    label: string;
};

type Props<T extends string> = {
    title: string;
    subtitle?: string;
    /** The account's tabs, in the order they are shown. Labels are already translated. */
    sections: Section<T>[];
    open: T;
    onOpen: (key: T) => void;
    children: ReactNode;
};

export function AccountLayout<T extends string>({
    title,
    subtitle,
    sections,
    open,
    onOpen,
    children,
}: Props<T>) {
    return (
        <StorefrontLayout title={title}>
            <div className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                <nav aria-label={title}>
                    <div className="grid gap-1" role="tablist" aria-orientation="vertical">
                        {sections.map((section) => (
                            <button
                                key={section.key}
                                type="button"
                                role="tab"
                                id={`tab-${section.key}`}
                                aria-selected={open === section.key}
                                aria-controls={`panel-${section.key}`}
                                data-test={`tab-${section.key}`}
                                onClick={() => onOpen(section.key)}
                                className={[
                                    'rounded-md px-3 py-2 text-start text-sm transition-colors',
                                    open === section.key
                                        ? 'bg-brand-soft font-medium text-brand'
                                        : 'text-ink-muted hover:bg-surface-sunken',
                                ].join(' ')}
                            >
                                {section.label}
                            </button>
                        ))}
                    </div>
                </nav>

                <section
                    role="tabpanel"
                    id={`panel-${open}`}
                    aria-labelledby={`tab-${open}`}
                    className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card"
                >
                    <div className="grid gap-1">
                        <h1 className="text-lg font-semibold text-ink">{title}</h1>
                        {subtitle ? <p className="text-sm text-ink-muted">{subtitle}</p> : null}
                    </div>

                    {children}
                </section>
            </div>
        </StorefrontLayout>
    );
}
