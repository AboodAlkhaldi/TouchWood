import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, LogOut, Moon, Settings, Sun } from 'lucide-react';
import { Logo } from '@/components/Logo';
import { MenuIcon } from '@/components/MenuIcon';
import { choosePreference } from '@/components/Preferences';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
| The panel's sidebar (frontend.md §2.2).
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
    const { viewer, menu, locale, theme } = page.props;
    const t = useTranslator();

    // The path only, so a search or a filter in the query string does not un-light the entry the
    // person is standing on.
    const here = page.url.split('?')[0] ?? page.url;

    return (
        <Sidebar collapsible="icon" className="border-sidebar-line">
            <SidebarHeader className="border-b border-sidebar-line">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild tooltip="TouchWood">
                            <Link href="/admin">
                                <Logo className="size-5 shrink-0 text-sidebar-ink" />
                                <span className="grid">
                                    <span className="truncate text-sm font-semibold">TouchWood</span>
                                    <span className="truncate text-xs text-sidebar-ink-muted">
                                        {t('admin.panel')}
                                    </span>
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {menu.map((group) => (
                    <SidebarGroup key={group.key}>
                        <SidebarGroupLabel className="text-sidebar-ink-muted">
                            {group.label}
                        </SidebarGroupLabel>

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
                                                <span>{entry.label}</span>
                                            </Link>
                                        </SidebarMenuButton>

                                        {entry.comingSoon ? (
                                            <SidebarMenuBadge className="text-sidebar-ink-muted">
                                                {t('admin.coming_soon')}
                                            </SidebarMenuBadge>
                                        ) : null}
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                ))}
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-line">
                <SidebarMenu>
                    <SidebarMenuItem>
                        {/* The person block, and everything that is theirs rather than the shop's:
                            their own account, how the panel looks to them, and the way out (owner's
                            final word on the sidebar, 2026-09-23). On the rail there is no room for
                            any of it, which is the reason it is a menu rather than four buttons. */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <SidebarMenuButton size="lg" tooltip={viewer?.name ?? t('admin.panel')}>
                                    {viewer?.avatarUrl ? (
                                        <img
                                            src={viewer.avatarUrl}
                                            alt=""
                                            className="size-8 shrink-0 rounded-pill object-cover"
                                        />
                                    ) : (
                                        <span className="grid size-8 shrink-0 place-items-center rounded-pill bg-sidebar-active/50 text-sm">
                                            {(viewer?.name ?? '?').slice(0, 1)}
                                        </span>
                                    )}

                                    <span className="grid min-w-0 flex-1 text-start">
                                        <span className="truncate text-sm">{viewer?.name}</span>
                                        <span className="truncate text-xs text-sidebar-ink-muted">
                                            {viewer?.roleLabel ?? t('admin.super_admin')}
                                        </span>
                                    </span>

                                    <ChevronsUpDown className="ms-auto size-4 shrink-0" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>

                            <DropdownMenuContent
                                side="top"
                                align="start"
                                className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                            >
                                {/* Their name again, because on the rail the trigger is only an
                                    avatar and this menu is the one place it is still written. */}
                                <DropdownMenuLabel className="truncate">{viewer?.name}</DropdownMenuLabel>

                                <DropdownMenuSeparator />

                                <DropdownMenuItem asChild>
                                    <Link href="/admin/account">
                                        <Settings />
                                        {t('admin.account_settings')}
                                    </Link>
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    onSelect={() => choosePreference('theme', theme.mode === 'dark' ? 'light' : 'dark', '/admin/preferences')}
                                >
                                    {theme.mode === 'dark' ? <Sun /> : <Moon />}
                                    {t(`admin.theme.${theme.mode === 'dark' ? 'light' : 'dark'}`)}
                                </DropdownMenuItem>

                                {/* Written in the language being offered, never translated: somebody
                                    who cannot read the current language must still recognise it. */}
                                <DropdownMenuItem
                                    lang={locale === 'ar' ? 'en' : 'ar'}
                                    onSelect={() => choosePreference('locale', locale === 'ar' ? 'en' : 'ar', '/admin/preferences')}
                                >
                                    {locale === 'ar' ? 'English' : 'العربية'}
                                </DropdownMenuItem>

                                <DropdownMenuSeparator />

                                {/* A9. A sign-out must change something, so it is a post. */}
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => router.post('/admin/sign-out')}
                                >
                                    <LogOut />
                                    {t('access::auth.sign_out')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>

            <SidebarRail />
        </Sidebar>
    );
}
