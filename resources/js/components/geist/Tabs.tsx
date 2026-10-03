import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { cx } from './cx';
import { Tooltip } from './Tooltip';

/*
| Geist's Tabs (frontend.md 1.10): sibling views inside one page - five to seven at most.
|
| Each title is a Title Case destination noun, one or two words ("Profile", "Security"), never a
| verb and never a count in the title (a count goes in the badge, and the badge goes at zero). The
| active tab lives in the URL, so a link or a refresh lands on the same view - which is why these
| tabs are links. A disabled tab says why. Changing tab never asks the server for confirmation and
| never toasts.
*/

export type TabItem = {
    title: string;
    href: string;
    active: boolean;
    badge?: ReactNode;
    disabledReason?: string;
    'data-test'?: string;
};

export function Tabs({ tabs, 'aria-label': label }: { tabs: TabItem[]; 'aria-label': string }) {
    return (
        <nav aria-label={label} className="-mb-px flex gap-1 overflow-x-auto border-b border-line">
            {tabs.map((tab) => {
                const look = cx(
                    'inline-flex h-10 shrink-0 items-center gap-1.5 border-b-2 px-3 text-label-14 transition-colors',
                    tab.active ? 'border-ink text-ink' : 'border-transparent text-ink-muted hover:text-ink',
                );

                if (tab.disabledReason !== undefined) {
                    return (
                        <Tooltip key={tab.href} text={tab.disabledReason}>
                            <span role="link" aria-disabled="true" tabIndex={0} className={cx(look, 'cursor-not-allowed text-ink-subtle hover:text-ink-subtle')}>
                                {tab.title}
                            </span>
                        </Tooltip>
                    );
                }

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        data-test={tab['data-test']}
                        aria-current={tab.active ? 'page' : undefined}
                        preserveScroll
                        className={look}
                    >
                        {tab.title}
                        {tab.badge === undefined || tab.badge === 0 ? null : tab.badge}
                    </Link>
                );
            })}
        </nav>
    );
}
