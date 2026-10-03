import { useState } from 'react';
import { defaultFilter } from 'cmdk';
import { Check, ChevronsUpDown } from 'lucide-react';
import { cn } from 'cn';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useTranslator } from '@/lib/t';

/*
| Picking a country from the whole world (frontend.md §3.3, C3): shadcn's `combobox-demo` as it is
| written - a Popover holding a Command - with a label and our words. Geist's Select is for short,
| fixed lists; past that, Geist's Combobox, where typing filters the list.
|
| The countries our stores are in come first, under their own heading; they appear in the long
| list too, so somebody looking for Saudi Arabia under S still finds it (owner, 2026-09-24). An
| empty search says what was typed, quoted (Geist's Combobox: No {items} match "{query}").
*/

export type CountryOption = { code: string; name: string; ours: boolean };

type Props = {
    id: string;
    label: string;
    countries: CountryOption[];
    value: string;
    onChange: (code: string) => void;
    error?: string;
};

export function CountryCombobox({ id, label, countries, value, onChange, error }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const chosen = countries.find((country) => country.code === value);

    const choose = (code: string) => {
        onChange(code);
        setOpen(false);
        setQuery('');
    };

    const item = (country: CountryOption, group: string) => (
        <CommandItem key={`${group}-${country.code}`} value={`${group}-${country.code}`} keywords={[country.name, country.code]} onSelect={() => choose(country.code)}>
            {country.name}
            <Check aria-hidden="true" className={cn('ms-auto', value === country.code ? 'opacity-100' : 'opacity-0')} />
        </CommandItem>
    );

    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? `${id}-error` : undefined}
                        className="w-full justify-between font-normal"
                        data-test={id}
                    >
                        {chosen?.name ?? ''}
                        <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
                    {/* Scored on the country's name and code only. An item's value has to tell the two
                        lists' copies apart ("ours-SA", "all-SA"), and scoring it too made "al" match
                        every "all-" item (the review of batch A). cmdk's own scorer, given the words. */}
                    <Command filter={(_value, search, keywords) => defaultFilter((keywords ?? []).join(' '), search)}>
                        <CommandInput placeholder={t('access::staff.country_search')} value={query} onValueChange={setQuery} className="h-9" />
                        <CommandList>
                            <CommandEmpty>{t('access::staff.country_none', { query })}</CommandEmpty>
                            <CommandGroup heading={t('access::staff.countries_ours')}>
                                {countries.filter((country) => country.ours).map((country) => item(country, 'ours'))}
                            </CommandGroup>
                            <CommandGroup heading={t('access::staff.countries_all')}>{countries.map((country) => item(country, 'all'))}</CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </Field>
    );
}
