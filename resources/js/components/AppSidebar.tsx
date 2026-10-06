import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, Home } from 'lucide-react';
import { useState } from 'react';
import { MenuIcon } from '@/components/MenuIcon';
import { NavUser } from '@/components/NavUser';
import { PanelHomeLink } from '@/components/PanelHomeLink';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar';
import { cn } from '@/lib/cn';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { MenuEntry, MenuGroup, SharedProps } from '@/types/page';

/*
| The panel's sidebar (frontend.md §2.2): shadcn's `sidebar-07` block, as it writes it (§1.11) -
| the link to Home in the header, the menu in the content, the person in the footer, and the rail.
|
| The menu is sidebar-07's main navigation (the owner's fix list, 2026-10-04, from a screenshot of
| it): each business area one row with its icon and a chevron, its screens indented under it.
| Several areas may be open at once; the one holding the screen being read opens by itself, and the
| screen is lit. Which areas are open is this browser's: a cookie the server reads back, as shadcn's
| own `sidebar_state` is, so the first paint is already right.
|
| It holds only what this person may do: Platform's menu registry answered that before the page was
| rendered, entry by entry, through Access's authorizer. What is offered is never what is allowed -
| every screen behind a link checks its own permission again in its handler (handoff §19).
|
| It collapses to a rail of icons rather than sliding away entirely (owner, 2026-09-23), so the
| person keeps a way into every area at any width: on the rail an area's icon opens its screens as
| a menu (sidebar-06's "submenus as dropdowns"), since a rail has no room to unfold them.
|
| Arabic mirrors the whole thing. The sidebar sits at side="left", which the component writes as
| start-0 rather than left-0, so the page's own direction decides which edge that is.
*/

/** The cookie the server reads back (HandleInertiaRequests::SIDEBAR_SECTIONS_COOKIE). */
const SECTIONS_COOKIE = 'sidebar_sections';

/** A year, as the panel's other preferences: re-choosing them every session would be a nuisance. */
const SECTIONS_MAX_AGE = 60 * 60 * 24 * 365;

/** Whether the person is on this entry's screen, or on one underneath it (/admin/staff/{id}). */
function isHere(here: string, entry: MenuEntry): boolean {
    return here === entry.href || here.startsWith(`${entry.href}/`);
}

/** How many wait behind an area's screens, together - failed jobs, say (frontend.md E7). */
function waitingIn(group: MenuGroup): number {
    return group.entries.reduce((sum, entry) => sum + (entry.count ?? 0), 0);
}

export function AppSidebar() {
    const page = usePage<SharedProps>();
    const { menu, sidebarSections } = page.props;
    const t = useTranslator();

    // The path only, so a search or a filter in the query string does not un-light the entry the
    // person is standing on.
    const here = page.url.split('?')[0] ?? page.url;
    const current = menu.find((group) => group.entries.some((entry) => isHere(here, entry)))?.key;

    // What the person opened, as this browser remembers it; the area being read opens by itself on
    // every page besides, unless they fold it here - and only what they opened is remembered, so an
    // area does not stay open on other pages because they once stood in it.
    const [remembered, setRemembered] = useState<Set<string>>(() => new Set(sidebarSections));
    const [currentFolded, setCurrentFolded] = useState(false);
    const isOpen = (key: string) => remembered.has(key) || (key === current && !currentFolded);

    const toggle = (key: string, opened: boolean) => {
        const next = new Set(remembered);

        if (opened) {
            next.add(key);
        } else {
            next.delete(key);
        }

        if (key === current) {
            setCurrentFolded(!opened);
        }

        setRemembered(next);
        // Dots join the keys: a comma is not allowed in a cookie's value. Only the panel reads it.
        document.cookie = `${SECTIONS_COOKIE}=${[...next].join('.')}; path=/admin; max-age=${SECTIONS_MAX_AGE}; samesite=lax`;
    };

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <PanelHomeLink />
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarMenu>
                        {/* Home first, a plain link like a one-screen area (the owner's fix list, point 6). */}
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild isActive={here === '/admin'} tooltip={t('admin.home.title')} data-test="menu-home">
                                <Link href="/admin">
                                    <Home aria-hidden="true" />
                                    <span>{t('admin.home.title')}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        {menu.map((group) => (
                            <MenuArea
                                key={group.key}
                                group={group}
                                here={here}
                                open={isOpen(group.key)}
                                onOpenChange={(opened) => toggle(group.key, opened)}
                            />
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>

            {/* shadcn writes its name in English; the page's language reads it (owner, 2026-10-03). */}
            <SidebarRail aria-label={t('admin.sidebar_toggle')} title={t('admin.sidebar_toggle')} />
        </Sidebar>
    );
}

type AreaProps = {
    group: MenuGroup;
    here: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * One business area: a row that unfolds its screens, or, on the rail, opens them as a menu. An area
 * with a single screen is that screen's link instead, named for where it goes - unfolding it would
 * show one item, often with the area's own name; it folds once a second screen joins it (owner,
 * 2026-10-04).
 */
function MenuArea({ group, here, open, onOpenChange }: AreaProps) {
    const page = usePage<SharedProps>();
    const { state, isMobile } = useSidebar();
    const t = useTranslator();
    const { locale } = page.props;
    const holdsHere = group.entries.some((entry) => isHere(here, entry));
    const waiting = waitingIn(group);
    // Said aloud whether or not a number shows; after a pause, so it is not read as the name.
    const waitingWords = waiting > 0 ? <span className="sr-only">{t('admin.menu_waiting', { count: String(waiting) })}</span> : null;

    const entry = group.entries.length === 1 ? group.entries[0] : undefined;

    if (entry !== undefined) {
        return (
            <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={holdsHere} tooltip={entry.label} data-test={`area-${group.key}`}>
                    <Link href={entry.href}>
                        <MenuIcon name={group.key} />
                        <span>
                            {entry.label}
                            {waitingWords}
                        </span>
                    </Link>
                </SidebarMenuButton>

                {entry.comingSoon ? <SidebarMenuBadge>{t('admin.coming_soon')}</SidebarMenuBadge> : null}

                {/* The number waiting behind it (frontend.md E7); on the rail of icons, where the
                    number has no room, a dot on the icon, ringed in the sidebar's ink - red alone on
                    the navy was 2.07:1 (the review, 2026-10-03). */}
                {waiting > 0 ? (
                    <>
                        <SidebarMenuBadge aria-hidden="true" data-test={`count-${entry.module}.${entry.key}`}>
                            {figure(locale, waiting)}
                        </SidebarMenuBadge>
                        <span
                            aria-hidden="true"
                            data-test={`dot-${group.key}`}
                            className="pointer-events-none absolute end-1 top-1 hidden size-2 rounded-full bg-destructive ring-1 ring-sidebar-foreground group-data-[collapsible=icon]:block"
                        />
                    </>
                ) : null}
            </SidebarMenuItem>
        );
    }

    if (state === 'collapsed' && !isMobile) {
        return (
            <DropdownMenu>
                <SidebarMenuItem>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton tooltip={group.label} isActive={holdsHere} data-test={`area-${group.key}`}>
                            <MenuIcon name={group.key} />
                            <span>
                                {group.label}
                                {waitingWords}
                            </span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    {/* Something waits in the area: a dot on its icon, ringed in the sidebar's ink -
                        red alone on the navy was 2.07:1 (the review, 2026-10-03). */}
                    {waiting > 0 ? (
                        <span
                            aria-hidden="true"
                            data-test={`dot-${group.key}`}
                            className="pointer-events-none absolute end-1 top-1 size-2 rounded-full bg-destructive ring-1 ring-sidebar-foreground"
                        />
                    ) : null}
                    <DropdownMenuContent side="right" align="start" className="min-w-56">
                        <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {group.entries.map((entry) => (
                            <DropdownMenuItem key={`${entry.module}.${entry.key}`} asChild>
                                <Link href={entry.href} aria-current={isHere(here, entry) ? 'page' : undefined}>
                                    <MenuIcon name={entry.icon} />
                                    <span className="flex-1">{entry.label}</span>
                                    {entry.count !== null && entry.count > 0 ? <span className="tabular-nums">{figure(locale, entry.count)}</span> : null}
                                    {entry.comingSoon ? <span className="text-ink-muted">{t('admin.coming_soon')}</span> : null}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </SidebarMenuItem>
            </DropdownMenu>
        );
    }

    return (
        <Collapsible asChild open={open} onOpenChange={onOpenChange} className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    {/* Folded around the screen being read, the area itself is lit, so the person
                        still sees where they are. */}
                    <SidebarMenuButton tooltip={group.label} isActive={holdsHere && !open} data-test={`area-${group.key}`}>
                        <MenuIcon name={group.key} />
                        <span>
                            {group.label}
                            {open ? null : waitingWords}
                        </span>
                        {/* Folded, the area carries what waits in it, so nothing waiting is hidden. */}
                        {!open && waiting > 0 ? (
                            <span aria-hidden="true" data-test={`area-waiting-${group.key}`} className="ms-auto text-xs font-medium tabular-nums">
                                {figure(locale, waiting)}
                            </span>
                        ) : null}
                        <ChevronRight
                            aria-hidden="true"
                            className={cn(
                                'transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90',
                                !open && waiting > 0 ? null : 'ms-auto',
                                // Pointing the way the page reads; open, down in both languages.
                                page.props.direction === 'rtl' ? 'rotate-180' : null,
                            )}
                        />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub>
                        {group.entries.map((entry) => (
                            <SidebarMenuSubItem key={`${entry.module}.${entry.key}`}>
                                <SidebarMenuSubButton asChild isActive={isHere(here, entry)}>
                                    <Link href={entry.href}>
                                        <span>
                                            {entry.label}
                                            {entry.count !== null && entry.count > 0 ? (
                                                <span className="sr-only">{t('admin.menu_waiting', { count: String(entry.count) })}</span>
                                            ) : null}
                                        </span>
                                    </Link>
                                </SidebarMenuSubButton>

                                {/* Placed by hand: shadcn's badge takes its height from a menu button beside it, which a
                                    screen under an area does not have, and would drop below its row. */}
                                {entry.comingSoon ? <SidebarMenuBadge className="top-1">{t('admin.coming_soon')}</SidebarMenuBadge> : null}

                                {/* The number waiting behind it, when there is any (frontend.md E7). */}
                                {entry.count !== null && entry.count > 0 ? (
                                    <SidebarMenuBadge aria-hidden="true" className="top-1" data-test={`count-${entry.module}.${entry.key}`}>
                                        {figure(locale, entry.count)}
                                    </SidebarMenuBadge>
                                ) : null}
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
