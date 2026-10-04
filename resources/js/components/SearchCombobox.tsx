import { useState } from 'react';
import { defaultFilter } from 'cmdk';
import { Check, ChevronsUpDown } from 'lucide-react';
import { cn } from 'cn';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/*
| Picking one value from a long list by typing (frontend.md §1.11): shadcn's `combobox-demo` as it
| is written - a Popover holding a Command - with a label and the page's words. Geist's Select is
| for short, fixed lists, "under ~10 items"; past that, Geist's Combobox, where typing filters the
| list: a store's time zone (several hundred), the audit log's actions (more with every module).
|
| An empty search says what was typed, quoted (Geist's Combobox: No {items} match "{query}"). Items
| are scored on their label and value only, with cmdk's own scorer.
*/

export type SearchOption = {
    value: string;
    label: string;
    /** A second line under the label. */
    description?: string;
    /** Shown, and out of reach: its description says why. */
    disabled?: boolean;
};

export type SearchWords = {
    /** A scoped placeholder: "Search time zones". */
    search: string;
    /** When nothing matches, with the typed text quoted. */
    none: (query: string) => string;
};

type Props = {
    id: string;
    label: string;
    options: SearchOption[];
    value: string;
    onChange: (value: string) => void;
    words: SearchWords;
    helper?: string;
    error?: string;
    /** Values written left to right whatever the page's language: time zone names, keys. */
    ltr?: boolean;
    className?: string;
    'data-test'?: string;
};

export function SearchCombobox({ id, label, options, value, onChange, words, helper, error, ltr = false, className, ...rest }: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const chosen = options.find((option) => option.value === value);
    const described = [helper === undefined ? null : `${id}-helper`, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    const choose = (next: string) => {
        onChange(next);
        setOpen(false);
        setQuery('');
    };

    const text = (label: string) => (ltr ? <bdi dir="ltr">{label}</bdi> : label);

    return (
        <Field className={className}>
            <FieldLabel id={`${id}-label`} htmlFor={id}>
                {label}
            </FieldLabel>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        // The field's name and then the value chosen: the label alone would hide it.
                        aria-labelledby={`${id}-label ${id}-value`}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={described}
                        className="w-full justify-between font-normal"
                        data-test={rest['data-test'] ?? id}
                    >
                        <span id={`${id}-value`} className="truncate">
                            {chosen === undefined ? '' : text(chosen.label)}
                        </span>
                        <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
                    <Command filter={(_value, search, keywords) => defaultFilter((keywords ?? []).join(' '), search)}>
                        <CommandInput placeholder={words.search} value={query} onValueChange={setQuery} className="h-9" />
                        <CommandList>
                            <CommandEmpty>{words.none(query)}</CommandEmpty>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={`option-${option.value}`}
                                    keywords={[option.label, option.value, option.description ?? '']}
                                    disabled={option.disabled}
                                    onSelect={() => choose(option.value)}
                                >
                                    {option.description === undefined ? (
                                        text(option.label)
                                    ) : (
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="truncate">{text(option.label)}</span>
                                            <span className="text-copy-12 whitespace-pre-line text-ink-muted">{option.description}</span>
                                        </span>
                                    )}
                                    <Check aria-hidden="true" className={cn('ms-auto shrink-0', value === option.value ? 'opacity-100' : 'opacity-0')} />
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {helper === undefined ? null : <FieldDescription id={`${id}-helper`}>{helper}</FieldDescription>}
            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </Field>
    );
}
