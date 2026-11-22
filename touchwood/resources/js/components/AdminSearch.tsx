import { lazy, Suspense, useEffect, useState, type ComponentType } from 'react';
import { Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useTranslator } from '@/lib/t';

/*
| The panel's header search (flags-2026-11.md S7, owner 2026-11-18): a button, and Ctrl K (⌘K on a Mac), that
| open a dialog over the pages the person may open. This file is the small part every admin page carries; the
| dialog (shadcn's Command) is its own chunk, fetched the first time it is opened. Only this file lands on every
| admin page: about 0.84 KB gzipped with shadcn's Kbd (measured 2026-11-20; JavaScriptBudgetTest's named pages).
*/

// A file gone after a deploy draws nothing, rather than taking the whole page down (as the payment parts do).
const AdminSearchDialog = lazy<ComponentType<{ open: boolean; onOpenChange: (open: boolean) => void }>>(() =>
    import('./AdminSearchDialog').then((module) => ({ default: module.AdminSearchDialog })).catch(() => ({ default: () => null })),
);

export function AdminSearch() {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [asked, setAsked] = useState(false);
    // Read after mount, so the server's HTML and the first render agree.
    const [mac, setMac] = useState(false);

    useEffect(() => {
        const onMac = /mac|iphone|ipad/i.test(navigator.platform);
        setMac(onMac);

        const onKey = (event: KeyboardEvent) => {
            // ⌘K on a Mac, Ctrl K elsewhere (S7): a Mac's Control K is "delete to the end of the line" in a text box.
            // Another searchable list (cmdk's, as in the role and country pickers) moves its highlight up with
            // Ctrl K, so the key is left to it there.
            const inOtherList = (event.target as Element).closest?.('[cmdk-root]:not([data-test="admin-search-command"])');

            if ((onMac ? event.metaKey : event.ctrlKey) && !event.altKey && !inOtherList && (event.code === 'KeyK' || event.key.toLowerCase() === 'k')) {
                event.preventDefault();
                setAsked(true);
                setOpen((now) => !now);
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const words = t('admin.search.button');

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                className="max-md:size-9 max-md:has-[>svg]:px-0 md:min-w-44 md:justify-start"
                aria-label={words}
                data-test="admin-search"
                onClick={() => {
                    setAsked(true);
                    setOpen(true);
                }}
            >
                <Search aria-hidden="true" />
                <span className="text-muted-foreground max-md:hidden">{words}</span>
                {/* shadcn's Kbd (frontend.md §1.11), as its docs show a shortcut inside a Button. */}
                <Kbd className="ms-auto max-md:hidden" dir="ltr">
                    {mac ? '⌘K' : 'Ctrl K'}
                </Kbd>
            </Button>
            {asked ? (
                <Suspense fallback={null}>
                    <AdminSearchDialog open={open} onOpenChange={setOpen} />
                </Suspense>
            ) : null}
        </>
    );
}
