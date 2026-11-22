import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    NavigationMenuTrigger,
} from '@/components/ui/navigation-menu';
import { useLink } from '@/lib/routes';
import type { ShopMenuItemData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The shop's category menu (catalog.md §4.5, P20), in every page's header: shadcn's Navigation Menu -
| "All Categories" opening the top categories with their sub-categories (the design's mega menu),
| and the top categories in a row. On a phone, the drawer's Categories (CategoryTree, content.md §1.5's option
| C3), loaded when it opens. A category is here by
| itself once the store lists something in it or below it for this viewer, in the store's order.
*/

type Words = Record<string, string>;

/** How many top categories the row holds, in the store's order, at the widest (shop-design.md §2.4); the rest under All Categories. */
const IN_ROW = 6;

/** Fewer as the screen narrows: 3 on a tablet, 5 on a laptop, 6 wider (one line always). */
const ROW_CLASS = ['', '', '', 'hidden lg:flex', 'hidden lg:flex', 'hidden xl:flex'];

// Measured before the browser paints, as MiddleTruncate does; on the server, where nothing is measured, a plain effect.
const useBrowserLayoutEffect = typeof window === 'undefined' ? useEffect : useLayoutEffect;

/**
 * The row under the header, from a tablet up (§2.4): one 50 px line - All Categories, the first top categories, and a
 * copper «Order wholesale» at its end for a guest or an individual (P21). All Categories opens across the box, 4 columns.
 *
 * **Only whole names that fit** (owner, 2026-11-20): long names once pushed «Order wholesale» off the screen (1333 px
 * on a 1280 px window). After the first paint, and whenever the row's width changes, the row counts the first top
 * categories whose own widths fit beside All Categories; the rest are only under All Categories, and «Order wholesale»
 * always shows. It counts the items' widths, not the list's, so the open All Categories panel never changes it. Until
 * the browser has measured - the server's render - the breakpoints of ROW_CLASS decide.
 */
export function CategoryMenuBar({ menu, words, wholesale }: { menu: ShopMenuItemData[]; words: Words; wholesale: boolean }) {
    const link = useLink();
    const area = useRef<HTMLDivElement>(null);
    const [fit, setFit] = useState<number | null>(null);

    useBrowserLayoutEffect(() => {
        const box = area.current;
        const list = box?.querySelector<HTMLElement>('[data-slot="navigation-menu-list"]');

        if (!box || !list) {
            return;
        }

        const count = () => {
            const items = [...list.children].filter((child): child is HTMLElement => child instanceof HTMLElement);
            const gap = parseFloat(getComputedStyle(list).columnGap) || 0;
            // Every row item drawn to be measured: the first is All Categories, then the top categories.
            items.forEach((item) => item.style.setProperty('display', 'flex'));
            let used = (items[0]?.getBoundingClientRect().width ?? 0);
            let fits = 0;

            for (const item of items.slice(1)) {
                used += gap + item.getBoundingClientRect().width;

                if (used > box.clientWidth + 0.5) {
                    break;
                }
                fits += 1;
            }
            items.forEach((item) => item.style.removeProperty('display'));
            setFit(fits);
        };

        count();
        const observer = new ResizeObserver(count);
        observer.observe(box);

        return () => observer.disconnect();
    }, [menu]);

    if (menu.length === 0) {
        return null;
    }

    return (
        <nav aria-label={words.categories} className="hidden border-t border-line md:block" data-test="category-menu">
            <div className="shop-box flex h-[50px] items-center gap-4">
                <div ref={area} className="min-w-0 flex-1">
                    <NavigationMenu viewport={false} className="max-w-none justify-start">
                        <NavigationMenuList className="flex-nowrap justify-start">
                            <NavigationMenuItem>
                                <NavigationMenuTrigger data-test="all-categories">{words.all_categories}</NavigationMenuTrigger>
                                <NavigationMenuContent>
                                    <ul className="grid w-[min(1320px,calc(100vw-5rem))] grid-cols-3 gap-6 p-6 lg:grid-cols-4" data-test="all-categories-panel">
                                        {menu.map((top) => (
                                            <li key={top.id} className="grid content-start gap-1">
                                                <NavigationMenuLink asChild>
                                                    <Link href={link('storefront.catalog.category', { slug: top.slug })} className="text-label-14 font-semibold text-ink">
                                                        {top.name}
                                                    </Link>
                                                </NavigationMenuLink>
                                                {top.children.map((child) => (
                                                    <NavigationMenuLink key={child.id} asChild>
                                                        <Link href={link('storefront.catalog.category', { slug: child.slug })} className="text-copy-14 text-ink-muted">
                                                            {child.name}
                                                        </Link>
                                                    </NavigationMenuLink>
                                                ))}
                                            </li>
                                        ))}
                                    </ul>
                                </NavigationMenuContent>
                            </NavigationMenuItem>
                            {menu.slice(0, IN_ROW).map((top, index) => (
                                <NavigationMenuItem key={top.id} className={fit === null ? ROW_CLASS[index] : index < fit ? '' : 'hidden'}>
                                    <NavigationMenuLink asChild>
                                        <Link href={link('storefront.catalog.category', { slug: top.slug })} className="text-[13.5px] whitespace-nowrap" data-test="top-category">
                                            {top.name}
                                        </Link>
                                    </NavigationMenuLink>
                                </NavigationMenuItem>
                            ))}
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>
                {wholesale ? (
                    <Link href={link('storefront.company')} className="shrink-0 text-[13.5px] font-semibold text-accent underline-offset-4 hover:underline" data-test="order-wholesale">
                        {words.order_wholesale}
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}
