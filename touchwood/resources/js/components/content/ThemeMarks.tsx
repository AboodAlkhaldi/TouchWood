import { usePage } from '@inertiajs/react';
import { ContentLink } from '@/components/content/ContentLink';
import { Badge } from '@/components/ui/badge';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { SharedProps } from '@/types/page';

/*
| A live theme's marks on a product (content.md §1.8 parts 6 and 7; catalog.md amendment 41(e)): the flag at a card's
| or a gallery's corner, the ribbon along a gallery's top, the badge's words with the card's labels - **on every product
| showing a reduced price now** (Q9 (a)): the price before is the test, nothing else is read. The artwork comes in the
| frame (`frame.content.theme`), so Catalog reads nothing of Content's. Transparent: WebP only (§1.8). Decorative,
| so read by nobody; the badge's words are read with the labels.
*/

/** Whether a price shows as reduced: a price before above the price now. */
export function isReduced(now: MoneyData | null | undefined, before: MoneyData | null | undefined): boolean {
    return now !== null && now !== undefined && before !== null && before !== undefined && before.minor > now.minor;
}

function useTheme() {
    return usePage<SharedProps>().props.frame?.content?.theme ?? null;
}

/** The flag, over the photo's start corner: its parent is `relative`. */
export function ThemeFlag({ reduced }: { reduced: boolean }) {
    const theme = useTheme();

    return reduced && theme?.flag ? <img src={theme.flag} alt="" aria-hidden="true" loading="lazy" decoding="async" className="pointer-events-none absolute start-2 top-2 size-12" data-test="theme-flag" /> : null;
}

/** The ribbon, a strip along the top of a product's gallery. */
export function ThemeRibbon({ reduced }: { reduced: boolean }) {
    const theme = useTheme();

    return reduced && theme?.ribbon ? <img src={theme.ribbon} alt="" aria-hidden="true" decoding="async" className="pointer-events-none absolute inset-x-0 top-0 h-auto w-full" data-test="theme-ribbon" /> : null;
}

/**
 * The cart's message (content.md §1.8 part 9; sales.md §9.10 A8): one line at the top of the cart while a theme lives, its
 * link optional. It comes in the frame: Sales reads nothing of Content's.
 */
export function ThemeCartMessage() {
    const { frame, shop, locale } = usePage<SharedProps>().props;
    const message = frame?.content?.theme?.cart ?? null;

    if (message === null || shop === null || shop === undefined) {
        return null;
    }

    return (
        <p className="rounded-md bg-accent-soft px-3 py-2 text-copy-14 text-ink" data-test="cart-message">
            <ContentLink link={message.link} store={shop.code} locale={locale} className="underline underline-offset-4">
                {message.text}
            </ContentLink>
        </p>
    );
}

/** The badge's words, in the system's badge style (the one style of every flag and badge is the themes step's, §1.16). */
export function ThemeBadge({ reduced }: { reduced: boolean }) {
    const theme = useTheme();

    return reduced && theme?.badge ? (
        <Badge variant="secondary" data-test="theme-badge">
            {theme.badge}
        </Badge>
    ) : null;
}
