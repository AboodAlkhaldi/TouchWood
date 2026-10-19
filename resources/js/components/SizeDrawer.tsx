import { type ReactNode, type RefObject, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';

/*
| A size's details in a side panel (pricing.md §4.3.1, Q3 - "View Prices", a size's stock and history):
| shadcn's Sheet as it is, from the end side - the right in English, the left in Arabic - 480 px wide,
| the full width under 640 px, on Geist's `material-menu`. Its own close button is ours, with words
| (`showCloseButton={false}`, frontend.md §1.11 edit 5), and focus goes back to what opened it.
|
| **Kept in the address** (`?size=`, `useAddressDrawer`): a refresh, a shared link or the browser's Back
| reopens or closes it, and the page's boxes stay as they are - opening it is a partial visit that keeps
| the page's state, asking only for the drawer's own data. Until that data arrives it shows shadcn's
| Spinner, "Loading" (§4.3.1, "Loading").
*/

type DrawerProps = {
    open: boolean;
    onClose: () => void;
    title: string;
    /** One sentence under the title: the size and its code, "60 cm · Oak · 5000123". */
    description: string;
    /** The drawer's data has not arrived yet. */
    loading: boolean;
    children: ReactNode;
    /** Where focus goes back when the opener is gone (a ⋯ menu's item). */
    returnFocusTo?: RefObject<HTMLElement | null>;
};

export function SizeDrawer({ open, onClose, title, description, loading, children, returnFocusTo }: DrawerProps) {
    const t = useTranslator();
    const returnFocus = useReturnFocus(open, returnFocusTo);

    return (
        <Sheet open={open} onOpenChange={(next) => (next ? undefined : onClose())}>
            <SheetContent
                side="right"
                showCloseButton={false}
                onCloseAutoFocus={returnFocus}
                // The end side in both directions: shadcn's "right" sits at the end, and in Arabic slides in from the left.
                className="material-menu w-full gap-0 border-0 p-0 sm:max-w-[480px] rtl:data-[state=closed]:slide-out-to-left rtl:data-[state=open]:slide-in-from-left"
                data-test="size-drawer"
            >
                <SheetHeader className="flex-row items-start justify-between gap-3 border-b border-line p-4">
                    <div className="grid min-w-0 gap-1">
                        <SheetTitle className="text-heading-20 text-ink">{title}</SheetTitle>
                        <SheetDescription className="text-copy-14 text-ink-muted">{description}</SheetDescription>
                    </div>
                    <Button type="button" variant="ghost" size="icon" onClick={onClose} aria-label={t('ui.close')} data-test="size-drawer-close">
                        <XIcon aria-hidden="true" />
                    </Button>
                </SheetHeader>
                <div className="grid flex-1 content-start gap-6 overflow-y-auto p-4">
                    {loading ? (
                        <div className="flex items-center gap-2 text-copy-14 text-ink-muted" data-test="size-drawer-loading">
                            <Spinner aria-hidden="true" />
                            <span role="status">{t('ui.loading')}</span>
                        </div>
                    ) : (
                        children
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

/**
 * A drawer whose open state is a value in the address - `?size=01K…` - opened and closed by partial
 * visits that keep the page as it is (its rows, its typed boxes, its scroll) and ask the server only for
 * the props named in `only`.
 *
 * @param key  the address's name for it, "size"
 * @param only  the page props the drawer reads, sent again when it opens
 */
export function useAddressDrawer(key: string, only: string[]) {
    const { url } = usePage();
    const [loading, setLoading] = useState(false);
    // The page's own address: a path and its query, read without the browser (the server renders it too).
    const address = new URL(url, 'http://localhost');
    const value = address.searchParams.get(key);
    const visit = (next: string | null) => {
        const query = Object.fromEntries(address.searchParams.entries());

        if (next === null) {
            delete query[key];
        } else {
            query[key] = next;
        }

        router.get(address.pathname, query, {
            only,
            preserveState: true,
            preserveScroll: true,
            onStart: () => setLoading(next !== null),
            onFinish: () => setLoading(false),
        });
    };

    return {
        /** The value the address holds, or null when the drawer is closed. */
        value: value === '' ? null : value,
        loading,
        open: (id: string) => visit(id),
        close: () => visit(null),
    };
}
