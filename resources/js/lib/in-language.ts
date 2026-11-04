import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';

/** The page's language picks one of the two as written. */
export function useInLanguage(): (ar: string | null, en: string | null) => string {
    const { locale } = usePage<SharedProps>().props;

    return (ar, en) => (locale === 'en' ? en : ar) ?? '';
}
