import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemTitle } from '@/components/ui/item';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';

/*
| What Content's lists draw the same way (content.md §4.2): the state badge, words as well as colour (§4.1), and a list's
| rows as cards on a phone (round 3 #15), each opening its item. Apart from the forms' parts (parts.tsx), so a list page
| pays for no form's code and a form for no list's (JavaScriptBudgetTest; lesson 195).
*/

const LOOKS: Record<string, Tone> = {
    DRAFT: 'gray-subtle',
    SCHEDULED: 'blue-subtle',
    LIVE: 'green-subtle',
    PUBLISHED: 'green-subtle',
    ENDED: 'gray-subtle',
    CANCELLED: 'gray-subtle',
};

export function StateBadge({ state }: { state: string }) {
    const t = useTranslator();
    const words: Record<string, string> = {
        DRAFT: t('content::admin_shared.state.DRAFT'),
        SCHEDULED: t('content::admin_shared.state.SCHEDULED'),
        LIVE: t('content::admin_shared.state.LIVE'),
        ENDED: t('content::admin_shared.state.ENDED'),
        PUBLISHED: t('content::admin_shared.state.PUBLISHED'),
        CANCELLED: t('content::admin_shared.state.CANCELLED'),
    };

    return (
        <Badge className={tone(LOOKS[state] ?? 'gray-subtle')} data-test="state">
            {words[state] ?? state}
        </Badge>
    );
}

/** A card opens its item: its own page (`href`), or a dialog (`onOpen`, told the control it opened from). */
export type Card = {
    id: string;
    title: ReactNode;
    badge?: ReactNode;
    lines: ReactNode[];
    menu?: ReactNode;
} & ({ href: string; onOpen?: never } | { href?: never; onOpen: (from: HTMLElement) => void });

/**
 * A list's rows on a phone (under 640, round 3 #15): one shadcn `Item` each - its title, badge and dates, its menu at the
 * end - the whole card opening the item. The title is the card's one control, a real link or a button, stretched over the
 * card, so the card is reached and opened from the keyboard as it is by a touch; the menu sits above it. The table beside
 * it is hidden at that width.
 */
export function PhoneCards({ cards, test }: { cards: Card[]; test: string }) {
    // Stretched over the card (`after:inset-0`): a press anywhere on it is a press on the title.
    const open = 'min-w-0 flex-1 truncate text-start after:absolute after:inset-0 after:content-[""] focus-visible:outline-none';

    return (
        <ItemGroup className="grid grid-cols-1 gap-2 sm:hidden" data-test={`${test}-cards`}>
            {cards.map((card) => (
                <Item key={card.id} variant="outline" className="material-base relative flex-nowrap has-[[data-open]:focus-visible]:border-ring has-[[data-open]:focus-visible]:ring-[3px] has-[[data-open]:focus-visible]:ring-ring/50" data-test={`${test}-card-${card.id}`}>
                    <ItemContent className="min-w-0 flex-1">
                        <ItemTitle className="w-full min-w-0">
                            {card.href !== undefined ? (
                                <Link href={card.href} className={open} data-open="" data-test={`${test}-card-open`}>
                                    {card.title}
                                </Link>
                            ) : (
                                <button type="button" className={open} onClick={(event) => card.onOpen(event.currentTarget)} data-open="" data-test={`${test}-card-open`}>
                                    {card.title}
                                </button>
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
