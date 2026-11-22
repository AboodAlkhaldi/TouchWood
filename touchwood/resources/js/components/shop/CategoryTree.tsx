import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import type { ShopMenuItemData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The category menu's tree as a phone shows it (catalog.md §4.5, P20): the top categories, each folding open on its
| sub-categories, with "All of {name}" first, each with how many products its page lists for this shopper (the owner's C3:
| "all categories with counts"; catalog.md amendment 46(a)). Drawn in the phone's drawer (content.md §1.5, the owner's option C3), which
| loads only when it is opened, so no shop page carries this on its first paint.
*/

type Words = Record<string, string>;

export function CategoryTree({ menu, words }: { menu: ShopMenuItemData[]; words: Words }) {
    return (
        <ul className="grid gap-1">
            {menu.map((item) => (
                <Branch key={item.id} item={item} words={words} />
            ))}
        </ul>
    );
}

/** How many products a category's page lists, quiet beside its name; none from a menu kept before the counts were. */
function Count({ products }: { products: number }) {
    const locale = usePage<SharedProps>().props.locale;

    if (products === 0) {
        return <span className="ms-auto" />;
    }

    return (
        <span className="tw-figure ms-auto ps-2 text-copy-13 text-ink-muted" data-test="category-count">
            {figure(locale, products)}
        </span>
    );
}

function Branch({ item, words }: { item: ShopMenuItemData; words: Words }) {
    const link = useLink();
    const href = link('storefront.catalog.category', { slug: item.slug });

    if (item.children.length === 0) {
        return (
            <li>
                <Link href={href} className="flex min-h-11 items-center rounded-md px-2 text-label-14 text-ink hover:bg-muted">
                    <span className="min-w-0 truncate">{item.name}</span>
                    <Count products={item.products} />
                </Link>
            </li>
        );
    }

    return (
        <li>
            <Collapsible>
                <CollapsibleTrigger className="group flex min-h-11 w-full items-center rounded-md px-2 text-label-14 text-ink hover:bg-muted">
                    <span className="min-w-0 truncate">{item.name}</span>
                    <Count products={item.products} />
                    <ChevronDown aria-hidden="true" className="ms-2 size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul className="grid gap-1 ps-3">
                        <li>
                            <Link href={href} className="flex min-h-11 items-center rounded-md px-2 text-copy-14 text-ink-muted hover:bg-muted">
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
