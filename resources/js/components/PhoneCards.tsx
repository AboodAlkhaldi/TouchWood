import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemTitle } from '@/components/ui/item';

/*
| A list's rows on a phone (round 3 #15, content.md §4.2): under 640 px each row of a list page draws as one shadcn
| `Item` - its title, its badge, two or three key fields, its row menu at the end - and the table beside it is hidden
| (`hidden sm:block` on the table's frame). Above 640 the table as before. CSS `hidden` takes the shape not shown out of
| the accessibility tree, so a row is never read or reached twice.
|
| The page passes each row to both shapes: the table's cells and a card's parts. A card that opens its item has its title
| as the one control, a real link or a button, stretched over the card, so the card is reached and opened from the
| keyboard as it is by a touch; the menu sits above it. A row that opens nothing (a report's line) is a plain card.
*/

/** A card opens its item: its own page (`href`), a dialog (`onOpen`, told the control it opened from), or nothing. */
export type PhoneCard = {
    id: string;
    title: ReactNode;
    badge?: ReactNode;
    lines: ReactNode[];
    menu?: ReactNode;
    /** Its own page; it wins over `onOpen`. */
    href?: string;
    onOpen?: (from: HTMLElement) => void;
};

export function PhoneCards({ cards, test }: { cards: PhoneCard[]; test: string }) {
    // Stretched over the card (`after:inset-0`): a press anywhere on it is a press on the title.
    const open = 'min-w-0 flex-1 truncate text-start after:absolute after:inset-0 after:content-[""] focus-visible:outline-none';

    return (
        <ItemGroup className="grid grid-cols-1 gap-2 sm:hidden" data-test={`${test}-cards`}>
            {cards.map((card) => (
                <Item
                    key={card.id}
                    role="listitem"
                    variant="outline"
                    className="material-base relative flex-nowrap has-[[data-open]:focus-visible]:border-ring has-[[data-open]:focus-visible]:ring-[3px] has-[[data-open]:focus-visible]:ring-ring/50"
                    data-test={`${test}-card-${card.id}`}
                >
                    <ItemContent className="min-w-0 flex-1">
                        <ItemTitle className="w-full min-w-0">
                            {card.href !== undefined ? (
                                <Link href={card.href} className={open} data-open="" data-test={`${test}-card-open`}>
                                    {card.title}
                                </Link>
                            ) : card.onOpen !== undefined ? (
                                <button type="button" className={open} onClick={(event) => card.onOpen?.(event.currentTarget)} data-open="" data-test={`${test}-card-open`}>
                                    {card.title}
                                </button>
                            ) : (
                                <span className="min-w-0 flex-1 truncate">{card.title}</span>
                            )}
                            {card.badge}
                        </ItemTitle>
                        {card.lines.map((line, index) => (
                            <ItemDescription key={index} className="truncate">
                                {line}
                            </ItemDescription>
                        ))}
                    </ItemContent>
                    {card.menu === undefined ? null : <ItemActions className="relative z-10">{card.menu}</ItemActions>}
                </Item>
            ))}
        </ItemGroup>
    );
}
