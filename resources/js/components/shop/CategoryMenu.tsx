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

/**
 * The row under the header, from a tablet up (§2.4): one 50 px line - All Categories, the first top categories, and a
 * copper «Order wholesale» at its end for a guest or an individual (P21). All Categories opens across the box, 4 columns.
 */
export function CategoryMenuBar({ menu, words, wholesale }: { menu: ShopMenuItemData[]; words: Words; wholesale: boolean }) {
    const link = useLink();

    if (menu.length === 0) {
        return null;
    }

    return (
        <nav aria-label={words.categories} className="hidden border-t border-line md:block" data-test="category-menu">
            <div className="shop-box flex h-[50px] items-center gap-4">
                <NavigationMenu viewport={false} className="max-w-none flex-1 justify-start">
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
                            <NavigationMenuItem key={top.id} className={ROW_CLASS[index]}>
                                <NavigationMenuLink asChild>
                                    <Link href={link('storefront.catalog.category', { slug: top.slug })} className="text-[13.5px] whitespace-nowrap" data-test="top-category">
                                        {top.name}
                                    </Link>
                                </NavigationMenuLink>
                            </NavigationMenuItem>
                        ))}
                    </NavigationMenuList>
                </NavigationMenu>
                {wholesale ? (
                    <Link href={link('storefront.company')} className="shrink-0 text-[13.5px] font-semibold text-accent underline-offset-4 hover:underline" data-test="order-wholesale">
                        {words.order_wholesale}
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}
