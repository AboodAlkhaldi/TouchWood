import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, Languages, LogOut, Settings } from 'lucide-react';
import { choosePreference } from '@/components/Preferences';
import { ThemeMenuSwitcher } from '@/components/geist-only/ThemeSwitcher';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The person block at the foot of the sidebar (frontend.md §2.2, §3.1): shadcn's `sidebar-07`
| nav-user, as the block writes it (§1.11). Everything that is theirs rather than the shop's lives
| here: their own account, how the panel looks to them, its language, and the way out.
|
| The theme switch is here and nowhere else in the panel (owner, 2026-10-02: Geist's "once per app,
| in the footer or settings" - the sidebar's footer). On the rail of icons there is room for none of
| it, which is why it is a menu: one click from any page, the sidebar collapsed included.
*/

export function NavUser() {
    const { viewer, locale, theme } = usePage<SharedProps>().props;
    const { isMobile } = useSidebar();
    const t = useTranslator();

    const name = viewer?.name ?? t('admin.panel');
    const role = viewer?.roleLabel ?? t('admin.super_admin');
    const other = locale === 'ar' ? 'en' : 'ar';

    const person = (
        <>
            {/* Decoration: their name is written beside it, so the initial is not read out too. */}
            <Avatar aria-hidden="true" className="h-8 w-8 rounded-lg">
                {viewer?.avatarUrl ? <AvatarImage src={viewer.avatarUrl} alt="" /> : null}
                <AvatarFallback className="rounded-lg">{name.slice(0, 1)}</AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-start text-sm leading-tight">
                <span className="truncate font-medium">{name}</span>
                <span className="truncate text-xs">{role}</span>
            </div>
        </>
    );

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={name}
                            data-test="person-menu"
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            {person}
                            <ChevronsUpDown className="ms-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        side={isMobile ? 'bottom' : 'right'}
                        align="end"
                        sideOffset={4}
                    >
                        {/* Their name again, because on the rail the trigger is only an avatar
                            and this is the one place it is still written. */}
                        <DropdownMenuLabel className="p-0 font-normal">
                            <div className="flex items-center gap-2 px-1 py-1.5 text-start text-sm">{person}</div>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuItem asChild>
                                <Link href="/admin/account">
                                    <Settings />
                                    {t('admin.account_settings')}
                                </Link>
                            </DropdownMenuItem>
                            {/* Written in the language being offered, never translated: somebody
                                who cannot read the current language must still recognise it. */}
                            <DropdownMenuItem data-test="language" onSelect={() => choosePreference('locale', other, '/admin/preferences')}>
                                <Languages />
                                <span lang={other}>{other === 'ar' ? 'العربية' : 'English'}</span>
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                        <ThemeMenuSwitcher value={theme.choice} onChange={(choice) => choosePreference('theme', choice, '/admin/preferences')} />
                        <DropdownMenuSeparator />
                        {/* A9. A sign-out must change something, so it is a post. Destructive, so it
                            is last, after the divider (Geist's Menu). */}
                        <DropdownMenuItem variant="destructive" onSelect={() => router.post('/admin/sign-out')}>
                            <LogOut />
                            {t('admin.sign_out')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
