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

/** The row under the header, from a tablet up. */
export function CategoryMenuBar({ menu, words }: { menu: ShopMenuItemData[]; words: Words }) {
    const link = useLink();

    if (menu.length === 0) {
        return null;
    }

    return (
        <nav aria-label={words.categories} className="hidden border-t border-line md:block" data-test="category-menu">
            <div className="mx-auto w-full max-w-6xl px-4">
                <NavigationMenu viewport={false} className="max-w-none justify-start">
                    <NavigationMenuList className="flex-wrap justify-start">
                        <NavigationMenuItem>
                            <NavigationMenuTrigger data-test="all-categories">{words.all_categories}</NavigationMenuTrigger>
                            <NavigationMenuContent>
                                <ul className="grid w-[min(48rem,90vw)] grid-cols-2 gap-4 p-4 lg:grid-cols-3">
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
                        {menu.map((top) => (
                            <NavigationMenuItem key={top.id}>
                                <NavigationMenuLink asChild>
                                    <Link href={link('storefront.catalog.category', { slug: top.slug })} className="text-label-14" data-test="top-category">
                                        {top.name}
                                    </Link>
                                </NavigationMenuLink>
                            </NavigationMenuItem>
                        ))}
                    </NavigationMenuList>
                </NavigationMenu>
            </div>
        </nav>
    );
}
