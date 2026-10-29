import { useState } from 'react';
import { defaultFilter } from 'cmdk';
import { Check, ChevronsUpDown } from 'lucide-react';
import { cn } from 'cn';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import type { BoxCheck } from '@/lib/use-checks';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { PlaceOption } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| One of the address form's place pickers - region, city or district (frontend.md §3.6, F9 on
| Platform's places; §8.4 Q4, Q5, Q9). shadcn's `combobox-demo` as it is written, a Popover holding a
| Command, as the shared CountryCombobox: a city list runs to hundreds, past what a Select is for.
|
| Searched in both languages, so "Jeddah" finds جدة on the Arabic page. "Other" comes last, and also
| when a search finds nothing, for a place the list does not hold yet (C2, shipping.md §9.3 #10): the
| form then shows a box to type it in. The list is read when the picker is first opened, so the page
| carries none of it (Q7).
*/

export const OTHER = 'other';

export type PlaceList = { state: 'idle' | 'loading' | 'failed' } | { state: 'ready'; places: PlaceOption[] };

type Props = {
    id: string;
    label: string;
    list: PlaceList;
    /** A place's id, OTHER, or '' while nothing is chosen. */
    value: string;
    /** The name the chosen place shows when it is not in the list as read: kept as saved (Q8), or retired (Q9). */
    shownName?: string;
    retired?: boolean;
    /** Offers "Other": the city's and the district's lists, never the region's (76(d)). */
    other: boolean;
    /** Why it is out of reach: no region chosen yet, say. */
    disabledReason?: string;
    /** Under it: "You wrote: Jeddah" on an address typed before the list arrived (Q10). */
    helper?: string;
    check: BoxCheck;
    onOpen: () => void;
    onPick: (value: string) => void;
};

export function PlacePicker({ id, label, list, value, shownName, retired = false, other, disabledReason, helper, check, onOpen, onPick }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const places = list.state === 'ready' ? list.places : [];
    const found = places.find((place) => place.id === value);
    const name = value === OTHER ? t('access::account.places.other') : (shownName ?? found?.name ?? '');
    const described = [helper ? `${id}-helper` : null, disabledReason ? `${id}-reason` : null, check.message ? `${id}-error` : null].filter(Boolean).join(' ');

    const choose = (picked: string) => {
        onPick(picked);
        check.onType();
        setOpen(false);
        setQuery('');
    };

    return (
        <Field>
            <FieldLabel id={`${id}-label`} htmlFor={id}>
                {label}
            </FieldLabel>
            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (next) onOpen();
                    else check.onLeave();
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-labelledby={`${id}-label ${id}-value`}
                        aria-invalid={check.message ? true : undefined}
                        aria-describedby={described === '' ? undefined : described}
                        aria-required={check.required || undefined}
                        disabled={disabledReason !== undefined}
                        className="w-full justify-between font-normal"
                        data-test={id}
                    >
                        <span id={`${id}-value`} className="flex min-w-0 items-center gap-2">
                            <span className="truncate">{name === '' ? <span className="text-ink-muted">{t('access::account.places.pick')}</span> : name}</span>
                            {retired ? (
                                <Badge className={tone('amber-subtle')} data-test={`${id}-retired`}>
                                    {t('access::account.places.no_longer_listed')}
                                </Badge>
                            ) : null}
                        </span>
                        <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
                    {list.state === 'ready' ? (
                        // Scored on both names only: an item's value is its id (the CountryCombobox's rule).
                        <Command filter={(_value, search, keywords) => (keywords?.[0] === OTHER ? 1 : defaultFilter((keywords ?? []).join(' '), search))}>
                            <CommandInput placeholder={t('access::account.places.search')} value={query} onValueChange={setQuery} className="h-9" />
                            <CommandList>
                                <CommandEmpty>{t('access::account.places.no_match', { query })}</CommandEmpty>
                                <CommandGroup>
                                    {places.map((place) => (
                                        <CommandItem key={place.id} value={place.id} keywords={[place.name, place.otherName]} onSelect={() => choose(place.id)} data-test={`${id}-option`}>
                                            <bdi>{place.name}</bdi>
                                            <Check aria-hidden="true" className={cn('ms-auto', value === place.id ? 'opacity-100' : 'opacity-0')} />
                                        </CommandItem>
                                    ))}
                                    {/* Last, and kept whatever is typed: a place the list lacks is still an address. */}
                                    {other ? (
                                        <CommandItem value={OTHER} keywords={[OTHER]} onSelect={() => choose(OTHER)} data-test={`${id}-other`}>
                                            {t('access::account.places.other')}
                                            <Check aria-hidden="true" className={cn('ms-auto', value === OTHER ? 'opacity-100' : 'opacity-0')} />
                                        </CommandItem>
                                    ) : null}
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    ) : list.state === 'failed' ? (
                        <div className="grid justify-items-start gap-2 p-3 text-copy-14 text-ink-muted" role="alert">
                            {t('access::account.places.load_failed')}
                            <Button type="button" variant="outline" size="sm" onClick={onOpen} data-test={`${id}-retry`}>
                                {t('access::account.places.try_again')}
                            </Button>
                        </div>
                    ) : (
                        <div className="flex items-center gap-2 p-3 text-copy-14 text-ink-muted" role="status">
                            <Spinner aria-hidden="true" />
                            {t('access::account.places.loading')}
                        </div>
                    )}
                </PopoverContent>
            </Popover>
            {helper ? (
                <FieldDescription id={`${id}-helper`} data-test={`${id}-helper`}>
                    {helper}
                </FieldDescription>
            ) : null}
            {disabledReason ? <FieldDescription id={`${id}-reason`}>{disabledReason}</FieldDescription> : null}
            {check.message ? (
                <FieldError id={`${id}-error`} role={check.typed ? undefined : 'alert'}>
                    {check.message}
                </FieldError>
            ) : null}
        </Field>
    );
}
