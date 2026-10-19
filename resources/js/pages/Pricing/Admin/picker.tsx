import { useState, type ReactNode } from 'react';
import { ChevronsUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useDebounced, useLiveRead, useT } from './parts';

/*
| Picking one thing from a list the server searches (pricing.md §4.3.3: P2's Product filter, P3's Left Out,
| P4's size for Add Wholesale Prices): shadcn's combobox - a Popover holding a Command - as SearchCombobox
| draws it, with the list read from the server 300 ms after the last key instead of filtered on the page
| (cmdk's own filter off). An empty search says what was typed, quoted (Geist's Combobox).
*/

type Props<T> = {
    id: string;
    label: string;
    /** The read and what it is asked besides the search (`q`). */
    path: string;
    params: Record<string, string>;
    /** The items of an answer. */
    rows: (answer: unknown) => T[];
    keyOf: (item: T) => string;
    labelOf: (item: T) => string;
    /** What a row draws besides its label: a photo, codes. */
    render?: (item: T) => ReactNode;
    /** What the closed box shows: the choice, or a prompt. */
    shown: string;
    onChoose: (item: T) => void;
    words: { search: string; none: (query: string) => string };
    helper?: string;
    error?: string;
    disabled?: boolean;
    'data-test'?: string;
};

export function RemoteCombobox<T>({ id, label, path, params, rows, keyOf, labelOf, render, shown, onChoose, words, helper, error, disabled = false, ...rest }: Props<T>) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const typed = useDebounced(query, 300);
    const found = useLiveRead<unknown>(path, open ? { ...params, q: typed } : null, 0);
    const items = found.data === null ? [] : rows(found.data);

    return (
        <Field>
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
                        aria-labelledby={`${id}-label ${id}-value`}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={[helper ? `${id}-helper` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined}
                        disabled={disabled}
                        className="w-full justify-between font-normal"
                        data-test={rest['data-test'] ?? id}
                    >
                        <span id={`${id}-value`} className="truncate">
                            {shown}
                        </span>
                        <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-(--radix-popover-trigger-width) min-w-72 p-0" align="start">
                    <Command shouldFilter={false}>
                        <CommandInput placeholder={words.search} value={query} onValueChange={setQuery} className="h-9" />
                        <CommandList aria-busy={found.loading || undefined}>
                            {found.loading && found.data === null ? (
                                <div className="flex justify-center p-3">
                                    <Spinner aria-label={t('ui.loading')} className="text-ink-muted" />
                                </div>
                            ) : (
                                <CommandEmpty>{words.none(typed)}</CommandEmpty>
                            )}
                            {items.map((item) => (
                                <CommandItem
                                    key={keyOf(item)}
                                    value={`item-${keyOf(item)}`}
                                    onSelect={() => {
                                        onChoose(item);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                    data-test={`${id}-option-${keyOf(item)}`}
                                >
                                    {render === undefined ? labelOf(item) : render(item)}
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {helper ? <FieldDescription id={`${id}-helper`}>{helper}</FieldDescription> : null}
            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </Field>
    );
}
