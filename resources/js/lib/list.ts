import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';

/**
 * A short list of names in the page's language: "Saudi Arabia, Egypt" on an English page,
 * "السعودية، مصر" on an Arabic one. The pages joined every list with the Arabic comma, so an
 * English page read "Saudi Arabia، Egypt" (found in batch A's screenshots, 2026-10-03).
 */
export function useList(): (items: string[]) => string {
    const { locale } = usePage<SharedProps>().props;
    const comma = locale === 'ar' ? '، ' : ', ';

    return (items) => items.join(comma);
}
