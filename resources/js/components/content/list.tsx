import type { ReactNode } from 'react';
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

export type Card = {
    id: string;
    title: ReactNode;
    badge?: ReactNode;
    lines: ReactNode[];
    menu?: ReactNode;
    onOpen: () => void;
};

/**
 * A list's rows on a phone (under 640, round 3 #15): one shadcn `Item` each - its title, badge and dates, its menu at the
 * end - the whole card opening the item. The table beside it is hidden at that width.
 */
export function PhoneCards({ cards, test }: { cards: Card[]; test: string }) {
    return (
        <ItemGroup className="grid gap-2 sm:hidden" data-test={`${test}-cards`}>
            {cards.map((card) => (
                <Item key={card.id} variant="outline" className="material-base cursor-pointer" onClick={card.onOpen} data-test={`${test}-card-${card.id}`}>
                    <ItemContent className="min-w-0">
                        <ItemTitle className="w-full min-w-0">
                            <span className="min-w-0 truncate">{card.title}</span>
                            {card.badge}
                        </ItemTitle>
                        {card.lines.map((line, index) => (
                            <ItemDescription key={index} className="truncate">
                                {line}
                            </ItemDescription>
                        ))}
                    </ItemContent>
                    {card.menu === undefined ? null : <ItemActions onClick={(event) => event.stopPropagation()}>{card.menu}</ItemActions>}
                </Item>
            ))}
        </ItemGroup>
    );
}
