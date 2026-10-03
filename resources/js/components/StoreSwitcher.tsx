import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, Lock } from 'lucide-react';
import { Logo } from '@/components/Logo';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useTranslator } from '@/lib/t';
import type { SharedProps, Store } from '@/types/page';

/*
| Which store the panel is working in (frontend.md §2.2), in the sidebar's header: shadcn's
| `sidebar-07` team switcher, as the block writes it, with our stores for its teams (§1.11).
|
| It never shows a store outside this person's stores - the list came from the server, which asked
| the authorizer. One store means its name, with no menu to open: there is nothing to choose. The
| choice is remembered on the account, so the next sign-in, on any device, opens where they left off.
|
| An off store is shown only to the staff who cover it, marked Off, and only a Super Admin may work
| in it, to prepare it before it opens; anyone else sees it disabled, with the reason (access.md
| amendment 58(a); Geist: a disabled action says why). The block's keyboard shortcuts and its "Add
| team" row are left out: neither does anything here, and a row that does nothing is a lie.
*/

export function StoreSwitcher() {
    const { store } = usePage<SharedProps>().props;
    const { isMobile } = useSidebar();
    const t = useTranslator();

    const current = store?.current ?? null;
    const title = current?.name ?? 'TouchWood';

    const header = (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                <Logo className="size-4" />
            </div>
            <div className="grid flex-1 text-start text-sm leading-tight">
                <span className="truncate font-medium">{title}</span>
                <span className="truncate text-xs">{t('admin.panel')}</span>
            </div>
            {/* A Super Admin preparing an off store sees it said where they work, not only in the
                list (the review of the foundation, 2026-10-03). */}
            {current !== null && !current.isActive ? (
                <Badge variant="outline" className="border-sidebar-foreground/40 text-sidebar-foreground">
                    {t('admin.store.off')}
                </Badge>
            ) : null}
        </>
    );

    if (store === null || store.available.length < 2) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" asChild tooltip={title}>
                        <Link href="/admin">{header}</Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={title}
                            data-test="store-switcher"
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            {header}
                            <ChevronsUpDown className="ms-auto" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={isMobile ? 'bottom' : 'right'}
                        sideOffset={4}
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">{t('admin.store.stores')}</DropdownMenuLabel>
                        {store.available.map((one) => (
                            <StoreRow key={one.id} store={one} current={one.id === current?.id} />
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

function StoreRow({ store, current }: { store: Store; current: boolean }) {
    const t = useTranslator();
    const locked = !store.choosable;

    return (
        <DropdownMenuItem
            // Not Radix's `disabled`, which takes the row out of the arrow keys and out of a screen
            // reader's reach: a disabled thing still says why (Geist's Menu, MenuItemLocked). It
            // stays reachable, says it is unavailable, and choosing it does nothing.
            aria-disabled={locked ? 'true' : undefined}
            data-locked={locked ? 'true' : undefined}
            data-test={`store-${store.id}`}
            aria-current={current ? 'true' : undefined}
            className="gap-2 p-2"
            onSelect={(event) => {
                if (locked) {
                    event.preventDefault();

                    return;
                }

                if (!current) {
                    router.post('/admin/current-store', { store: store.id }, { preserveScroll: true });
                }
            }}
        >
            <div aria-hidden="true" className="flex size-6 shrink-0 items-center justify-center rounded-md border text-xs font-medium">
                {locked ? <Lock className="size-3.5" /> : store.name.slice(0, 1)}
            </div>
            <div className="grid min-w-0 flex-1">
                <span className={current ? 'truncate font-medium' : 'truncate'}>{store.name}</span>
                {/* Why it cannot be chosen, in words, at the muted text's own contrast. */}
                {locked ? <span className="truncate text-xs text-muted-foreground">{t('admin.store.off_reason', { store: store.name })}</span> : null}
            </div>
            {store.isActive ? null : <Badge variant="outline">{t('admin.store.off')}</Badge>}
        </DropdownMenuItem>
    );
}
