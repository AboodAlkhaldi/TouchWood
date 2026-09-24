import type { ReactNode } from 'react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';

/*
| A customer's own pages, inside the shop (frontend.md §2).
|
| It is the storefront's frame with a side list of the customer's pages, not a frame of its own: a
| person moving between the shop and their account must not feel they have left the site. The pages
| themselves arrive in step 4; what step 1 settles is that the layout exists and behaves.
*/

type Props = {
    title: string;
    /** The account pages, in the order they are shown. Labels are already translated. */
    sections: { key: string; label: string; href: string; current: boolean }[];
    children: ReactNode;
};

export function AccountLayout({ title, sections, children }: Props) {
    return (
        <StorefrontLayout title={title}>
            <div className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                <nav aria-label={title}>
                    <ul className="grid gap-1">
                        {sections.map((section) => (
                            <li key={section.key}>
                                <a
                                    href={section.href}
                                    aria-current={section.current ? 'page' : undefined}
                                    className={[
                                        'block rounded-md px-3 py-2 text-sm transition-colors',
                                        section.current
                                            ? 'bg-brand-soft text-brand font-medium'
                                            : 'text-ink-muted hover:bg-surface-sunken',
                                    ].join(' ')}
                                >
                                    {section.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <section className="rounded-lg border border-line bg-surface p-6 shadow-card">
                    <h1 className="mb-4 text-lg font-semibold">{title}</h1>
                    {children}
                </section>
            </div>
        </StorefrontLayout>
    );
}
