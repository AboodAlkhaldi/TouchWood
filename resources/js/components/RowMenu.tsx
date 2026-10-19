import { forwardRef, type ComponentProps } from 'react';
import { MoreHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useTranslator } from '@/lib/t';

/*
| A row's ⋯ menu on the store screens (pricing.md §4.3.1 "The frame"; Pricing's and Inventory's lists):
| the ⋯ button named for its row, and an item that may be out of reach with its reason written under it, as
| Catalog's lists draw theirs (frontend.md §1.11: shadcn's DropdownMenu under Geist's Menu rules).
*/

/** A row's ⋯ button: its name says whose actions, and a spinner stands in while one is on its way. */
export const MoreButton = forwardRef<HTMLButtonElement, ComponentProps<typeof Button> & { name: string; busy?: boolean }>(function MoreButton({ name, busy = false, ...rest }, ref) {
    const t = useTranslator();

    return (
        <Button ref={ref} type="button" variant="ghost" size="icon-sm" aria-label={`${t('ui.more_actions')}: ${name}`} aria-busy={busy || undefined} title={t('ui.more_actions')} {...rest}>
            {busy ? <Spinner aria-hidden="true" role={undefined} aria-label={undefined} /> : <MoreHorizontal aria-hidden="true" />}
        </Button>
    );
});

/**
 * A ⋯ item that may be out of reach: drawn, its reason written under it (Catalog's Guarded). Destructive
 * ones in red, last after a separator.
 */
export function Guarded({ label, reason, onSelect, test, destructive = false }: { label: string; reason?: string | null; onSelect: () => void; test: string; destructive?: boolean }) {
    const off = reason !== undefined && reason !== null;

    return (
        <DropdownMenuItem
            variant={destructive ? 'destructive' : 'default'}
            aria-disabled={off || undefined}
            className={off ? 'cursor-not-allowed' : undefined}
            onSelect={(event) => {
                if (off) {
                    event.preventDefault();

                    return;
                }

                onSelect();
            }}
            data-test={test}
        >
            <span className="grid gap-0.5">
                <span className={off ? 'opacity-60' : undefined}>{label}</span>
                {off ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
            </span>
        </DropdownMenuItem>
    );
}
