import { Link } from '@inertiajs/react';
import { ChevronDown, Menu } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    NavigationMenuTrigger,
} from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useLink } from '@/lib/routes';
import type { ShopMenuItemData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The shop's category menu (catalog.md §4.5, P20), in every page's header: shadcn's Navigation Menu -
| "All Categories" opening the top categories with their sub-categories (the design's mega menu),
| and the top categories in a row. On a phone, a Sheet with the tree folding. A category is here by
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

/** The phone's way in: a button opening the tree in a Sheet. */
export function CategoryMenuSheet({ menu, words }: { menu: ShopMenuItemData[]; words: Words }) {
    if (menu.length === 0) {
        return null;
    }

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="md:hidden" aria-label={words.open_menu} data-test="category-sheet-open">
                    <Menu aria-hidden="true" />
                </Button>
            </SheetTrigger>
            {/* "left" is the side the page starts on: the Sheet is written in logical sides (start-0), the right in Arabic. */}
            <SheetContent side="left" className="overflow-y-auto" data-test="category-sheet">
                <SheetHeader>
                    <SheetTitle>{words.categories}</SheetTitle>
                </SheetHeader>
                <ul className="grid gap-1 px-4 pb-6">
                    {menu.map((item) => (
                        <Branch key={item.id} item={item} words={words} />
                    ))}
                </ul>
            </SheetContent>
        </Sheet>
    );
}

function Branch({ item, words }: { item: ShopMenuItemData; words: Words }) {
    const link = useLink();
    const href = link('storefront.catalog.category', { slug: item.slug });

    if (item.children.length === 0) {
        return (
            <li>
                <Link href={href} className="block rounded-md px-2 py-2 text-label-14 text-ink hover:bg-muted">
                    {item.name}
                </Link>
            </li>
        );
    }

    return (
        <li>
            <Collapsible>
                <CollapsibleTrigger className="group flex w-full items-center justify-between rounded-md px-2 py-2 text-label-14 text-ink hover:bg-muted">
                    {item.name}
                    <ChevronDown aria-hidden="true" className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul className="grid gap-1 ps-3">
                        <li>
                            <Link href={href} className="block rounded-md px-2 py-2 text-copy-14 text-ink-muted hover:bg-muted">
                                {(words.all_of ?? ':name').replace(':name', item.name)}
                            </Link>
                        </li>
                        {item.children.map((child) => (
                            <Branch key={child.id} item={child} words={words} />
                        ))}
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </li>
    );
}
