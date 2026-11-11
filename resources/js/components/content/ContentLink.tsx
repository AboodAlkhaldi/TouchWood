import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';

/*
| A link Content's staff typed (content.md §1.1 rule 16): a page of the shop written without its store and language
| (`/brands/blum`), drawn under the page's own as an Inertia link, or a full https:// address, which opens in a new tab.
| Anything else was refused when it was saved; if one still arrives, or there is none, the words are drawn as they are.
*/

export function shopHref(link: string | null, store: string, locale: string): { href: string; external: boolean } | null {
    if (link === null) {
        return null;
    }

    if (link.startsWith('https://')) {
        return { href: link, external: true };
    }

    return link.startsWith('/') && !link.startsWith('//') ? { href: `/${store}/${locale}${link === '/' ? '' : link}`, external: false } : null;
}

type Props = { link: string | null; store: string; locale: string; className?: string; children: ReactNode; test?: string };

export function ContentLink({ link, store, locale, className, children, test }: Props) {
    const href = shopHref(link, store, locale);

    if (href === null) {
        return <>{children}</>;
    }

    return href.external ? (
        <a href={href.href} target="_blank" rel="noopener noreferrer" className={className} data-test={test}>
            {children}
        </a>
    ) : (
        <Link href={href.href} className={className} data-test={test}>
            {children}
        </Link>
    );
}
