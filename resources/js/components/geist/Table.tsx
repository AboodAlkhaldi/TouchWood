import type { ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import { Button } from './Button';
import { cx } from './cx';

/*
| Geist's Table, its pager, and the Load More Button (frontend.md 1.10).
|
| A table is for rows of one shape that are compared down a column. Headers are Title Case nouns,
| never sentences. A value that is unknown or does not apply is an em dash, never "N/A" or a blank.
| Figures line up (tabular numbers, the mono face where §1.8 allows it). An empty list is an Empty
| State outside the table, never an empty body. Rows of description plus one action are an Entity
| instead; a key/value block on a detail page is a Description.
|
| The pager reads "21–40 of 142" with an en dash, between Previous and Next; at either end the
| missing link is left out rather than greyed. Keyset lists that only go forward use Load More.
*/

export function Table({ children, className, 'aria-label': label }: { children: ReactNode; className?: string; 'aria-label'?: string }) {
    return (
        <div className={cx('material-base overflow-x-auto', className)}>
            <table aria-label={label} className="w-full border-collapse text-copy-14">
                {children}
            </table>
        </div>
    );
}

export function TableHeader({ children }: { children: ReactNode }) {
    return <thead className="bg-surface-sunken">{children}</thead>;
}

export function TableBody({ children }: { children: ReactNode }) {
    return <tbody className="divide-y divide-line">{children}</tbody>;
}

export function TableRow({ children, className, ...rest }: { children: ReactNode; className?: string; 'data-test'?: string }) {
    return (
        <tr {...rest} className={cx('transition-colors', className)}>
            {children}
        </tr>
    );
}

type HeadProps = ThHTMLAttributes<HTMLTableCellElement> & { numeric?: boolean };

export function TableHead({ children, numeric = false, className, ...rest }: HeadProps) {
    return (
        <th
            scope="col"
            {...rest}
            className={cx('h-10 whitespace-nowrap px-4 text-label-13 font-medium text-ink-muted', numeric ? 'text-end' : 'text-start', className)}
        >
            {children}
        </th>
    );
}

type CellProps = TdHTMLAttributes<HTMLTableCellElement> & { numeric?: boolean };

export function TableCell({ children, numeric = false, className, ...rest }: CellProps) {
    return (
        <td {...rest} className={cx('px-4 py-3 align-middle text-ink', numeric && 'tw-figure text-end', className)}>
            {children === null || children === undefined || children === '' ? <span className="text-ink-subtle">—</span> : children}
        </td>
    );
}

type PagerProps = {
    /** 1-based position of the first and last row shown, and the total. */
    from: number;
    to: number;
    total: number;
    previousHref: string | null;
    nextHref: string | null;
};

export function Pager({ from, to, total, previousHref, nextHref }: PagerProps) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    // Arabic pages show Arabic-Indic digits (frontend.md 1.8); Intl writes them for 'ar'.
    const figure = new Intl.NumberFormat(locale === 'ar' ? 'ar' : 'en');

    return (
        <nav aria-label={t('ui.pages')} className="flex flex-wrap items-center justify-between gap-3">
            <p className="tw-figure text-label-13 text-ink-muted">
                {t('ui.range', { from: figure.format(from), to: figure.format(to), total: figure.format(total) })}
            </p>
            <div className="flex items-center gap-2">
                {previousHref === null ? null : (
                    <Link
                        href={previousHref}
                        data-test="pager-previous"
                        className="inline-flex h-8 items-center gap-1 rounded-[var(--tw-radius)] bg-surface px-2.5 text-button-14 text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] hover:bg-surface-sunken"
                    >
                        <ChevronLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
                        {t('ui.previous')}
                    </Link>
                )}
                {nextHref === null ? null : (
                    <Link
                        href={nextHref}
                        data-test="pager-next"
                        className="inline-flex h-8 items-center gap-1 rounded-[var(--tw-radius)] bg-surface px-2.5 text-button-14 text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] hover:bg-surface-sunken"
                    >
                        {t('ui.next')}
                        <ChevronRight aria-hidden="true" className="size-4 rtl:rotate-180" />
                    </Link>
                )}
            </div>
        </nav>
    );
}

export function LoadMoreButton({ onClick, loading = false, 'data-test': test }: { onClick: () => void; loading?: boolean; 'data-test'?: string }) {
    const t = useTranslator();

    return (
        <Button type="secondary" onClick={onClick} loading={loading} data-test={test} className="w-full">
            {t('ui.load_more')}
        </Button>
    );
}
