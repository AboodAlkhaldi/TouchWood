import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { useLink } from '@/lib/routes';
import type { ShopMenuItemData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The category menu's tree as a phone shows it (catalog.md §4.5, P20): the top categories, each folding open on its
| sub-categories, with "All of {name}" first. Drawn in the phone's drawer (content.md §1.5, the owner's option C3), which
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

function Branch({ item, words }: { item: ShopMenuItemData; words: Words }) {
    const link = useLink();
    const href = link('storefront.catalog.category', { slug: item.slug });

    if (item.children.length === 0) {
        return (
            <li>
                <Link href={href} className="flex min-h-11 items-center rounded-md px-2 text-label-14 text-ink hover:bg-muted">
                    {item.name}
                </Link>
            </li>
        );
    }

    return (
        <li>
            <Collapsible>
                <CollapsibleTrigger className="group flex min-h-11 w-full items-center justify-between rounded-md px-2 text-label-14 text-ink hover:bg-muted">
                    {item.name}
                    <ChevronDown aria-hidden="true" className="size-4 transition-transform group-data-[state=open]:rotate-180" />
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
