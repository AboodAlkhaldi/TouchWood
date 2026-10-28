import { type ReactNode, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { SearchField } from '@/components/SearchField';
import { Badge } from '@/components/ui/badge';
import { Field, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { figure, intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { SharedProps } from '@/types/page';

/*
| The parts E8's two pages share (platform.md §4.3): the counts in the page's plural, the Retired mark,
| Show retired (off at first, Q5) and the filter box narrowing what is already loaded (Q6).
*/

export const P = 'platform::admin_places';

export function useLocale(): string {
    return usePage<SharedProps>().props.locale;
}

export function plural(t: ReturnType<typeof useTranslator>, locale: string, key: string, count: number): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count) });
}

export function RetiredBadge() {
    const t = useTranslator();

    return (
        <Badge className={tone('gray-subtle')} data-test="retired">
            {t(`${P}.retired`)}
        </Badge>
    );
}

/** A name in the page's language, the other under it - each isolated, as typed text in a sentence. */
export function Names({ name, other, children }: { name: string; other: string; children?: ReactNode }) {
    return (
        <span className="grid min-w-0 gap-0.5">
            <span className="flex flex-wrap items-center gap-2 text-label-14 text-ink">
                <bdi className="truncate">{name}</bdi>
                {children}
            </span>
            {other !== '' && other !== name ? (
                <bdi className="truncate text-copy-13 text-ink-muted">{other}</bdi>
            ) : null}
        </span>
    );
}

/**
 * The filter box and Show retired, over a list whose rows each have names and a retired mark: what
 * is in use first, retired ones after them and only while the switch is on.
 */
export function useNarrowed<T extends { name: string; otherName: string; retired: boolean }>(rows: T[]) {
    const [text, setText] = useState('');
    const [retired, setRetired] = useState(false);
    const needle = text.trim().toLocaleLowerCase();
    const match = (row: T) => needle === '' || row.name.toLocaleLowerCase().includes(needle) || row.otherName.toLocaleLowerCase().includes(needle);
    const shown = [...rows.filter((row) => !row.retired), ...(retired ? rows.filter((row) => row.retired) : [])].filter(match);

    return { text, setText, retired, setRetired, shown, anyRetired: rows.some((row) => row.retired), filtered: needle !== '' };
}

export function NarrowBar({ id, text, setText, retired, setRetired, anyRetired }: { id: string; text: string; setText: (text: string) => void; retired: boolean; setRetired: (on: boolean) => void; anyRetired: boolean }) {
    const t = useTranslator();

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <SearchField id={`${id}-filter`} label={t(`${P}.filter`)} placeholder={t(`${P}.filter`)} value={text} onValueChange={setText} className="max-w-xs" data-test="filter" />
            {anyRetired ? (
                <Field orientation="horizontal" className="w-fit">
                    <Switch id={`${id}-retired`} checked={retired} onCheckedChange={setRetired} className="data-[state=unchecked]:bg-ink-subtle" data-test="show-retired" />
                    <FieldLabel htmlFor={`${id}-retired`} className="text-copy-14">
                        {t(`${P}.show_retired`)}
                    </FieldLabel>
                </Field>
            ) : null}
        </div>
    );
}
