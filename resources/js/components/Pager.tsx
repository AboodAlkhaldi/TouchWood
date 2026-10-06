import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { Pagination, PaginationContent, PaginationItem } from '@/components/ui/pagination';
import { intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/**
 * Geist's pager: "1–20 of 142" between Previous and Next, on shadcn's Pagination; a missing end is
 * left out rather than greyed. Its links are Inertia's, in shadcn's link look, so a page turn does
 * not reload the page; their words are ours (ui.php), given from outside. The figures are the
 * Latin on every page (frontend.md §1.8, the owner 2026-10-06).
 */
export function Pager({ from, to, total, previousHref, nextHref }: { from: number; to: number; total: number; previousHref: string | null; nextHref: string | null }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const figure = new Intl.NumberFormat(intlLocale(locale));

    return (
        <Pagination aria-label={t('ui.pages')} className="justify-between">
            <p className="tw-figure self-center text-copy-13 text-ink-muted">{t('ui.range', { from: figure.format(from), to: figure.format(to), total: figure.format(total) })}</p>
            <PaginationContent>
                {previousHref === null ? null : (
                    <PaginationItem>
                        <Link href={previousHref} rel="prev" className={buttonVariants({ variant: 'ghost', size: 'default' })} data-test="previous-page">
                            <ChevronLeft aria-hidden="true" className="rtl:rotate-180" />
                            {t('ui.previous')}
                        </Link>
                    </PaginationItem>
                )}
                {nextHref === null ? null : (
                    <PaginationItem>
                        <Link href={nextHref} rel="next" className={buttonVariants({ variant: 'ghost', size: 'default' })} data-test="next-page">
                            {t('ui.next')}
                            <ChevronRight aria-hidden="true" className="rtl:rotate-180" />
                        </Link>
                    </PaginationItem>
                )}
            </PaginationContent>
        </Pagination>
    );
}
