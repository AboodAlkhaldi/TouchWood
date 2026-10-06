import { router, usePage } from '@inertiajs/react';
import { ChevronDown, Store as StoreIcon } from 'lucide-react';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| View Store, at the end of the panel's top bar (frontend.md §2.2; access.md §1.11): a store's shop,
| as this staff member - a post, since it opens a pass. **Which store** (the owner, 2026-10-06;
| amendment 64): with one store the button opens it; with more, a short menu of the person's stores -
| a Super Admin's off ones too, marked Off. No store, no button. The address is written out: the route
| helper would add its weight to every admin page (frontend.md §5's page budget).
*/

export function ViewStoreButton() {
    const { viewStores } = usePage<SharedProps>().props;
    const t = useTranslator();
    const stores = viewStores ?? [];
    const open = (code: string) => router.post('/admin/staff-view', { store: code });

    if (stores.length === 0) {
        return null;
    }

    if (stores.length === 1) {
        const only = stores[0];

        return (
            <Button variant="outline" size="sm" data-test="view-store" onClick={() => (only === undefined ? undefined : open(only.code))}>
                <StoreIcon aria-hidden="true" />
                {t('admin.staff_view.open')}
            </Button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" data-test="view-store">
                    <StoreIcon aria-hidden="true" />
                    {t('admin.staff_view.open')}
                    <ChevronDown aria-hidden="true" className="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                <DropdownMenuLabel className="text-xs text-muted-foreground">{t('admin.store.stores')}</DropdownMenuLabel>
                {stores.map((store) => (
                    <DropdownMenuItem key={store.code} data-test={`view-store-${store.code}`} onSelect={() => open(store.code)}>
                        <span className="flex-1 truncate">{store.name}</span>
                        {store.isActive ? null : <StoreOffBadge />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
