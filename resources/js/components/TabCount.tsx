import { usePage } from '@inertiajs/react';
import { figure } from '@/lib/digits';
import type { SharedProps } from '@/types/page';

/*
| How many rows a tab lists, beside its name in shadcn's underlined Tabs (round 3 #11, owner 2026-11-10: underlined
| tabs with counts everywhere) - the way Sync's read and Catalog's import already write theirs: Latin digits, the
| page's grouping, muted. Only on tabs that filter a list (plan pick K1). Nothing when the page has no count for it.
*/

export function TabCount({ count, test }: { count: number | undefined; test?: string }) {
    const { locale } = usePage<SharedProps>().props;

    return count === undefined ? null : (
        <span className="tw-figure text-copy-12 text-ink-muted" data-test={test}>
            {figure(locale, count)}
        </span>
    );
}
