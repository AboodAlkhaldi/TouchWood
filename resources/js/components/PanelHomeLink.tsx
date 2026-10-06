import { Link } from '@inertiajs/react';
import { Logo } from '@/components/Logo';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useTranslator } from '@/lib/t';

/*
| The sidebar's header (frontend.md §2.2; the owner, 2026-10-06): one button to Home - the logo,
| "TouchWood" and "Admin Panel" - and nothing else. It was `sidebar-07`'s team switcher, a menu of the
| stores; the panel has no store "worked in" any more, and each store screen chooses its own store
| (access.md amendment 64). The block's header row stays, as a link.
*/

export function PanelHomeLink() {
    const t = useTranslator();

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild tooltip={t('admin.home.title')}>
                    <Link href="/admin" data-test="panel-home">
                        {/* The navy tile: the sidebar is navy in both themes (owner's logo answers,
                            2026-10-04). Boxed, as sidebar-07 boxes its logo: the menu button sizes any
                            svg directly inside it to an icon's 16 px. */}
                        <span className="flex aspect-square size-8 shrink-0 items-center justify-center">
                            <Logo tone="navy" decorative className="size-8" />
                        </span>
                        <span className="grid flex-1 text-start text-sm leading-tight">
                            <span className="truncate font-medium">TouchWood</span>
                            <span className="truncate text-xs">{t('admin.panel')}</span>
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
