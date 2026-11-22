import { useEffect, useRef, type RefObject } from 'react';

/**
 * Focus goes back to the button that opened a form when the form closes - by its Cancel, or once
 * saved. Such a button steps out while its form is open (the owner, 2026-10-06: a form ends with its
 * own Cancel and main button), so nothing else would put focus anywhere, and it fell to the page's
 * start (the review of P7). The form's first field takes focus as it opens (autoFocus).
 *
 * Only when focus is lost with the form - on the page itself, or still inside the closing form
 * (`form`: Radix's Collapsible keeps its content for one render more): a form closed because another
 * opened leaves focus in the one that opened.
 */
export function useFocusBack(open: boolean, button: RefObject<HTMLElement | null>, form?: RefObject<HTMLElement | null>): void {
    const wasOpen = useRef(open);

    useEffect(() => {
        const active = document.activeElement;
        const lost = active === null || active === document.body || (form?.current?.contains(active) ?? false);

        if (wasOpen.current && !open && lost) {
            button.current?.focus();
        }
        wasOpen.current = open;
    }, [open, button, form]);
}
