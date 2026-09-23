import { type ReactNode, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { LanguageToggle, ThemeToggle } from '@/components/Preferences';
import { Logo } from '@/components/Logo';
import { StorePicker } from '@/components/StorePicker';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The admin panel's frame (frontend.md §2.2).
|
| The sidebar holds only what this person may do - Platform's menu registry answered that before the
| page was rendered, entry by entry, through Access's authorizer. What is offered is never what is
| allowed: every screen behind a link checks its own permission again in its handler (handoff §19).
|
| The panel carries no store in its URLs. The store is remembered on the account and shown in the
| header; a screen that is store-free simply ignores it.
|
| Arabic mirrors the whole frame, sidebar included, because the page's dir is set on <html> by the
| server and every offset here is written as start/end rather than left/right.
*/

type Props = {
    title: string;
    subtitle?: string;
    /** The one main action of the page, at the top end of the frame. */
    action?: ReactNode;
    children: ReactNode;
};

export function AdminLayout({ title, subtitle, action, children }: Props) {
    const { viewer, menu } = usePage<SharedProps>().props;
    const t = useTranslator();
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <>
            <Head title={title} />

            <div className="flex min-h-screen bg-page text-ink">
                {/* Below tablet width the sidebar slides in over the page instead of taking a
                    column of it; above, it is simply always there.

                    Every part of the drawer is scoped to max-lg, including the slide, rather than
                    being undone again at lg. A rule written as rtl:translate-x-full beats one
                    written as lg:translate-x-0 whichever order they appear in, which left the
                    sidebar sitting one width off the right edge of every Arabic desktop page
                    (found by running it, 2026-09-22). Scoped this way there is no rule at lg to
                    win against. */}
                <aside
                    className={[
                        'flex w-72 flex-col bg-sidebar text-sidebar-ink',
                        'max-lg:fixed max-lg:inset-y-0 max-lg:start-0 max-lg:z-40 max-lg:transition-transform',
                        menuOpen ? '' : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full',
                    ].join(' ')}
                >
                    <div className="flex items-center gap-3 border-b border-sidebar-line px-5 py-5">
                        <Logo className="text-sidebar-ink" />
                        <div className="grid">
                            <span className="text-sm font-semibold">TouchWood</span>
                            <span className="text-xs text-sidebar-ink-muted">{t('admin.panel')}</span>
                        </div>
                    </div>

                    <nav className="flex-1 overflow-y-auto px-3 py-4">
                        {menu.map((group) => (
                            <div key={group.key} className="mb-5">
                                <p className="px-2 pb-2 text-[11px] font-semibold tracking-wide text-sidebar-ink-muted uppercase">
                                    {group.label}
                                </p>
                                <ul className="grid gap-0.5">
                                    {group.entries.map((entry) => (
                                        <li key={`${entry.module}.${entry.key}`}>
                                            <Link
                                                href={entry.href}
                                                className="flex items-center justify-between rounded-md px-2 py-2 text-sm text-sidebar-ink/90 transition-colors hover:bg-sidebar-active/40"
                                            >
                                                <span>{entry.label}</span>
                                                {entry.comingSoon ? (
                                                    <span className="rounded-pill bg-sidebar-active/40 px-2 py-0.5 text-[10px]">
                                                        {t('admin.coming_soon')}
                                                    </span>
                                                ) : null}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </nav>

                    <div className="border-t border-sidebar-line px-4 py-4">
                        {viewer ? (
                            <div className="mb-3 flex items-center gap-3">
                                {viewer.avatarUrl ? (
                                    <img
                                        src={viewer.avatarUrl}
                                        alt=""
                                        className="size-9 rounded-pill object-cover"
                                    />
                                ) : (
                                    <span className="grid size-9 place-items-center rounded-pill bg-sidebar-active/50 text-sm">
                                        {viewer.name.slice(0, 1)}
                                    </span>
                                )}
                                <div className="grid min-w-0">
                                    <span className="truncate text-sm">{viewer.name}</span>
                                    <span className="truncate text-xs text-sidebar-ink-muted">
                                        {viewer.roleLabel ?? t('admin.super_admin')}
                                    </span>
                                </div>
                            </div>
                        ) : null}

                        <div className="flex items-center gap-2">
                            <LanguageToggle className="border-sidebar-line text-sidebar-ink-muted hover:border-sidebar-ink hover:text-sidebar-ink" />
                            <ThemeToggle className="border-sidebar-line text-sidebar-ink-muted hover:border-sidebar-ink hover:text-sidebar-ink" />
                        </div>

                        {/* The two things the person block offers (§3.1): their own account, and
                            the way out. Both are here rather than behind a popup - a menu that
                            has to be opened to find two items costs a click and, on a phone, a
                            second place for focus to get lost. */}
                        <Link
                            href="/admin/account"
                            className="mt-3 block w-full rounded-md border border-sidebar-line px-3 py-2 text-center text-xs text-sidebar-ink-muted transition-colors hover:border-sidebar-ink hover:text-sidebar-ink"
                        >
                            {t('admin.account_settings')}
                        </Link>

                        {/* A9. A sign-out must change something, so it is a post, never a link. */}
                        <button
                            type="button"
                            onClick={() => router.post('/admin/sign-out')}
                            className="mt-2 w-full rounded-md border border-sidebar-line px-3 py-2 text-xs text-sidebar-ink-muted transition-colors hover:border-sidebar-ink hover:text-sidebar-ink"
                        >
                            {t('access::auth.sign_out')}
                        </button>
                    </div>
                </aside>

                {menuOpen ? (
                    <button
                        type="button"
                        aria-label={t('admin.close_menu')}
                        onClick={() => setMenuOpen(false)}
                        className="fixed inset-0 z-30 bg-ink/40 lg:hidden"
                    />
                ) : null}

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center gap-4 border-b border-line bg-surface px-4 py-3 lg:px-6">
                        <button
                            type="button"
                            onClick={() => setMenuOpen((open) => !open)}
                            aria-label={menuOpen ? t('admin.close_menu') : t('admin.open_menu')}
                            className="rounded-md p-2 text-ink-muted hover:bg-surface-sunken lg:hidden"
                        >
                            {menuOpen ? <X className="size-5" /> : <Menu className="size-5" />}
                        </button>

                        <StorePicker />
                    </header>

                    <main className="flex-1 px-4 py-6 lg:px-8">
                        <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <h1 className="text-xl font-semibold text-ink">{title}</h1>
                                {subtitle ? (
                                    <p className="text-sm text-ink-muted">{subtitle}</p>
                                ) : null}
                            </div>
                            {action}
                        </div>

                        {children}
                    </main>
                </div>
            </div>

            <SyncDocument />
            <Toasts />
        </>
    );
}
