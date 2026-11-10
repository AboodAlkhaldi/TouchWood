import type { ReactNode } from 'react';
import { TextareaField, TextField } from '@/components/Fields';
import { TranslateButton, type Translation } from '@/components/content/translate';
import { Badge } from '@/components/ui/badge';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemTitle } from '@/components/ui/item';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { Checks } from '@/lib/use-checks';

/*
| What Content's screens draw the same way (content.md §4.2): the state badge, words as well as colour (§4.1); a pair
| of Arabic and English boxes side by side, stacked on a phone, each in its own direction, Translate beside the English;
| and a list's rows as cards on a phone (round 3 #15), each opening its item.
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

type PairProps = {
    /** The pair's name: its boxes post as `{name}_ar` and `{name}_en`. */
    name: string;
    labelAr: string;
    labelEn: string;
    helper?: string;
    ar: string;
    en: string;
    onChange: (language: 'ar' | 'en', value: string) => void;
    errors: Partial<Record<string, string>>;
    checks: Checks;
    translation: Translation;
    rows?: number;
    /** Typed marks (§1.2), sent to Translate block by block. */
    rich?: boolean;
};

/** Arabic and English side by side, stacked on a phone, each in its own direction, Translate under the English. */
export function PairFields({ name, labelAr, labelEn, helper, ar, en, onChange, errors, checks, translation, rows, rich = false }: PairProps) {
    const t = useTranslator();
    const marked = translation.marked.has(name);
    const note = translation.note(name);
    const Box = rows === undefined ? TextField : TextareaField;
    const english = `${name}_en`;
    const pair = { name, ar, en, rich, fill: (value: string) => onChange('en', value) };

    return (
        <div className="grid gap-4 sm:grid-cols-2" data-test={`pair-${name}`}>
            <Box
                id={`${name}_ar`}
                dir="rtl"
                lang="ar"
                label={labelAr}
                helper={helper}
                rows={rows}
                check={checks.box(`${name}_ar`, errors[`${name}_ar`])}
                value={ar}
                onChange={(event: { target: { value: string } }) => onChange('ar', toLatinDigits(event.target.value))}
                data-test={`${name}_ar`}
            />
            <div className="grid content-start gap-2">
                <Box
                    id={english}
                    dir="ltr"
                    lang="en"
                    label={labelEn}
                    helper={marked ? t('content::admin_shared.translate.marked') : helper}
                    rows={rows}
                    check={checks.box(english, errors[english])}
                    value={en}
                    onChange={(event: { target: { value: string } }) => {
                        translation.typed(name);
                        onChange('en', toLatinDigits(event.target.value));
                    }}
                    data-test={english}
                />
                {note === undefined ? null : (
                    <p className="text-copy-13 text-ink-muted" role="status" data-test={`translate-note-${name}`}>
                        {note}
                    </p>
                )}
                <div>
                    <TranslateButton translation={translation} pair={pair} />
                </div>
            </div>
        </div>
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
                    {card.menu === undefined ? null : (
                        <ItemActions onClick={(event) => event.stopPropagation()}>{card.menu}</ItemActions>
                    )}
                </Item>
            ))}
        </ItemGroup>
    );
}
