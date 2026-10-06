import { useRef, type KeyboardEvent } from 'react';
import { Search, X } from 'lucide-react';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { useTranslator } from '@/lib/t';

/*
| Geist's Search Input on shadcn's input-group (frontend.md §1.11), as shadcn's own example writes
| it (`input-group-icon`, `input-group-button`): a magnifying glass at the start, a clear button at
| the end once there is text, and Escape clears it (Geist's Input, "Search" variant).
|
| Geist's rules: a scoped placeholder ("Search staff"), and the field named for a screen reader,
| since there is no visible label. Escape clears only a field with text in it, so on an empty field
| it still closes whatever dialog holds it.
*/

type Props = {
    id: string;
    /** The field's name for a screen reader: there is no visible label. */
    label: string;
    /** Scoped to what is searched ("Search staff"), never an instruction. */
    placeholder?: string;
    value: string;
    onValueChange: (value: string) => void;
    /** What clearing does besides emptying the field: running the emptied search, for one. */
    onClear?: () => void;
    name?: string;
    'aria-describedby'?: string;
    'data-test'?: string;
    className?: string;
};

export function SearchField({ id, label, placeholder, value, onValueChange, onClear, className, ...rest }: Props) {
    const t = useTranslator();
    const input = useRef<HTMLInputElement>(null);

    // Focus stays in the field: the clear button goes away once the field is empty, and focus on
    // a button that is gone falls to the page (the review of batch A).
    const clear = () => {
        onValueChange('');
        onClear?.();
        input.current?.focus();
    };

    return (
        <InputGroup className={className}>
            <InputGroupInput
                {...rest}
                ref={input}
                id={id}
                type="search"
                // The browser's own clear mark would sit beside ours; Geist's is the one kept.
                className="[&::-webkit-search-cancel-button]:hidden"
                aria-label={label}
                placeholder={placeholder}
                value={value}
                onChange={(event) => onValueChange(event.target.value)}
                onKeyDown={(event: KeyboardEvent<HTMLInputElement>) => {
                    if (event.key === 'Escape' && value !== '') {
                        event.preventDefault();
                        event.stopPropagation();
                        clear();
                    }
                }}
            />
            <InputGroupAddon>
                <Search aria-hidden="true" />
            </InputGroupAddon>
            {value === '' ? null : (
                <InputGroupAddon align="inline-end">
                    <InputGroupButton size="icon-xs" aria-label={t('ui.clear_search')} title={t('ui.clear_search')} onClick={clear} data-test="clear-search">
                        <X aria-hidden="true" />
                    </InputGroupButton>
                </InputGroupAddon>
            )}
        </InputGroup>
    );
}
