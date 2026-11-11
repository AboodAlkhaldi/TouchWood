import { Link } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { Heart, Search, ShoppingCart, UserRound } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import type { SharedProps } from '@/types/page';

/*
| The phone's tab bar (content.md §1.5): the design's five, fixed at the bottom under 641 px, icons with their names;
| the page's own tab filled. **Explore opens search** (the owner, 2026-11-11): it brings the search row's box into view
| and puts the cursor in it. The cart's tab is Sales' (with its pieces), and not in a staff view, which orders nothing.
|
| Its own file, drawn once the page is in the browser (as Ops' newsletter box): the layout keeps its room at the bottom
| from the first paint, so nothing moves when it comes, and no shop page carries it before it shows.
*/

type Props = {
    /** The home tab's icon, the layout's: the panel's menu draws it too, and one copy is every page's already. */
    House: ComponentType<{ className?: string; 'aria-hidden'?: boolean | 'true' }>;
    shop: string;
    locale: string;
    url: string;
    words: Record<string, string>;
    sales: NonNullable<SharedProps['frame']>['sales'];
    /** A staff member looking: no cart and no favourites, as the header has none (it orders and saves nothing). */
    staffView: boolean;
};

export default function TabBar({ House, shop, locale, url, words, sales, staffView }: Props) {
    const link = useLink();
    const path = url.split('?')[0] ?? url;
    const base = `/${shop}/${locale}`;
    const tab = 'flex h-14 flex-1 flex-col items-center justify-center gap-0.5 text-[11px] leading-none text-ink';
    const on = (href: string, exact = false) => (exact ? path === href : path === href || path.startsWith(`${href}/`));
    const icon = (active: boolean) => (active ? 'size-6 fill-current' : 'size-6');
    const account = link('storefront.account');
    const favourites = link('storefront.feedback.wishlist');
    const inAccount = on(account) && !on(favourites);

    function explore() {
        const box = document.querySelector<HTMLInputElement>('[data-test="search-row"] input');
        box?.scrollIntoView({ block: 'center' });
        box?.focus();
    }

    return (
        <nav aria-label={words.tabs} className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] sm:hidden" data-test="tab-bar">
            <div className="flex">
                <Link href={base} className={tab} aria-current={on(base, true) ? 'page' : undefined} data-test="tab-home">
                    <House aria-hidden="true" className={icon(on(base, true))} />
                    {words.home}
                </Link>
                <button type="button" className={tab} onClick={explore} data-test="tab-explore">
                    <Search aria-hidden="true" className={icon(on(`${base}/search`))} />
                    {words.explore}
                </button>
                {sales ? (
                    <Link href={`${base}/cart`} className={tab} aria-current={on(`${base}/cart`) ? 'page' : undefined} data-test="tab-cart">
                        <span className="relative">
                            <ShoppingCart aria-hidden="true" className={icon(on(`${base}/cart`))} />
                            {sales.pieces > 0 ? (
                                <Badge className="tw-figure absolute -end-2 -top-1.5 h-4 min-w-4 rounded-full bg-accent px-1 text-[10px] leading-none text-ink-on-brand" data-test="tab-cart-pieces">
                                    {sales.pieces > 99 ? '99+' : figure(locale, sales.pieces)}
                                </Badge>
                            ) : null}
                        </span>
                        {words.cart}
                    </Link>
                ) : null}
                {staffView ? null : (
                    <Link href={favourites} className={tab} aria-current={on(favourites) ? 'page' : undefined} data-test="tab-favourites">
                        <Heart aria-hidden="true" className={icon(on(favourites))} />
                        {words.favourites}
                    </Link>
                )}
                <Link href={account} className={tab} aria-current={inAccount ? 'page' : undefined} data-test="tab-account">
                    <UserRound aria-hidden="true" className={icon(inAccount)} />
                    {words.account}
                </Link>
            </div>
        </nav>
    );
}
