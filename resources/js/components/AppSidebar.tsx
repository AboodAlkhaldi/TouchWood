import { Link, usePage } from '@inertiajs/react';
import { MenuIcon } from '@/components/MenuIcon';
import { NavUser } from '@/components/NavUser';
import { StoreSwitcher } from '@/components/StoreSwitcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The panel's sidebar (frontend.md §2.2): shadcn's `sidebar-07` block, as it writes it (§1.11) -
| the store switcher in the header, the menu in the content, the person in the footer, and the rail.
|
| It holds only what this person may do: Platform's menu registry answered that before the page was
| rendered, entry by entry, through Access's authorizer. What is offered is never what is allowed -
| every screen behind a link checks its own permission again in its handler (handoff §19).
|
| It collapses to a rail of icons rather than sliding away entirely (owner, 2026-09-23), so the
| person keeps a way into every area at any width. That is why a menu entry carries an icon: on the
| rail it is all there is left of it.
|
| Arabic mirrors the whole thing. The sidebar sits at side="left", which the component writes as
| start-0 rather than left-0, so the page's own direction decides which edge that is.
*/

export function AppSidebar() {
    const page = usePage<SharedProps>();
    const { menu } = page.props;
    const t = useTranslator();

    // The path only, so a search or a filter in the query string does not un-light the entry the
    // person is standing on.
    const here = page.url.split('?')[0] ?? page.url;

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <StoreSwitcher />
            </SidebarHeader>

            <SidebarContent>
                {menu.map((group) => (
                    <SidebarGroup key={group.key}>
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>

                        <SidebarGroupContent>
                            <SidebarMenu>
                                {group.entries.map((entry) => (
                                    <SidebarMenuItem key={`${entry.module}.${entry.key}`}>
                                        <SidebarMenuButton
                                            asChild
                                            // The entry the person is standing on, and the ones
                                            // underneath it: /admin/staff stays lit while they are
                                            // reading one person.
                                            isActive={here === entry.href || here.startsWith(`${entry.href}/`)}
                                            tooltip={entry.label}
                                        >
                                            <Link href={entry.href}>
                                                <MenuIcon name={entry.icon} />
                                                <span>
                                                    {entry.label}
                                                    {/* The count, said aloud whether or not the badge
                                                        shows - on the rail it does not (owner's #2).
                                                        Inside the label, so the label stays the last
                                                        span the sidebar truncates; after a pause, so
                                                        it is not read as part of the name. */}
                                                    {entry.count !== null && entry.count > 0 ? (
                                                        <span className="sr-only">{t('admin.menu_waiting', { count: String(entry.count) })}</span>
                                                    ) : null}
                                                </span>
                                            </Link>
                                        </SidebarMenuButton>

                                        {entry.comingSoon ? <SidebarMenuBadge>{t('admin.coming_soon')}</SidebarMenuBadge> : null}

                                        {/* The number waiting behind it, when there is any (frontend.md E7). */}
                                        {entry.count !== null && entry.count > 0 ? (
                                            <>
                                                <SidebarMenuBadge aria-hidden="true" data-test={`count-${entry.module}.${entry.key}`}>
                                                    {entry.count}
                                                </SidebarMenuBadge>
                                                {/* The badge hides on the rail of icons; a dot on the icon
                                                    says something waits (owner, 2026-09-29; kept, #2).
                                                    Ringed in the sidebar's ink: red alone on the
                                                    navy was 2.07:1 (the review, 2026-10-03). */}
                                                <span
                                                    aria-hidden="true"
                                                    data-test={`dot-${entry.module}.${entry.key}`}
                                                    className="pointer-events-none absolute end-1 top-1 hidden size-2 rounded-full bg-destructive ring-1 ring-sidebar-foreground group-data-[collapsible=icon]:block"
                                                />
                                            </>
                                        ) : null}
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>

            {/* shadcn writes its name in English; the page's language reads it (owner, 2026-10-03). */}
            <SidebarRail aria-label={t('admin.sidebar_toggle')} title={t('admin.sidebar_toggle')} />
        </Sidebar>
    );
}
