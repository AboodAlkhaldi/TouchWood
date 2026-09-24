import { Fragment, type ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { AppSidebar } from '@/components/AppSidebar';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { StorePicker } from '@/components/StorePicker';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { SharedProps } from '@/types/page';

/*
| The admin panel's frame (frontend.md §2.2).
|
| The sidebar is shadcn's, collapsing to a rail of icons (owner, 2026-09-23); what it holds is in
| AppSidebar. The frame's own job is the rest: the trigger, the trail back, the store being looked
| at, and the page itself.
|
| The panel carries no store in its URLs. The store is remembered on the account and shown in the
| header; a screen that is store-free simply ignores it.
|
| Arabic mirrors the whole frame, sidebar included, because the page's dir is set on <html> by the
| server and every offset here is written as start/end rather than left/right.
*/

/** One step of the trail back. The last step is the page itself and is never a link. */
export type Crumb = {
    label: string;
    href?: string;
};

type Props = {
    title: string;
    subtitle?: string;
    /** The one main action of the page, at the top end of the frame. */
    action?: ReactNode;
    /**
     * The way back, nearest last: [{ Staff, /admin/staff }, { Noura, /admin/staff/01... }].
     *
     * The page itself is added as the final step, so a screen never repeats its own title here.
     * Left out, the trail is worked out from the menu: the area this page belongs to, then the
     * page. That is right for a screen that sits directly under a menu entry and wrong for nothing,
     * which is why it is only a default.
     */
    breadcrumbs?: Crumb[];
    children: ReactNode;
};

export function AdminLayout({ title, subtitle, action, breadcrumbs, children }: Props) {
    const page = usePage<SharedProps>();
    const { menu, sidebarOpen } = page.props;
    const here = page.url.split('?')[0] ?? page.url;

    const trail: Crumb[] = [...(breadcrumbs ?? defaultTrail(menu, here)), { label: title }];

    return (
        <>
            <Head title={title} />

            {/* Open or shut is remembered per browser in a cookie, and read back by the server, so
                the first paint already has it right. Read here in the browser instead, the server's
                HTML and React's first render would disagree and the page would flicker - or worse,
                refuse to hydrate (found by running it, 2026-09-22). */}
            <SidebarProvider defaultOpen={sidebarOpen}>
                <AppSidebar />

                <SidebarInset className="bg-page text-ink">
                    <header className="flex items-center gap-3 border-b border-line bg-surface px-4 py-3 lg:px-6">
                        <SidebarTrigger className="text-ink-muted" />

                        <Separator orientation="vertical" className="h-5 bg-line" />

                        <Breadcrumb>
                            <BreadcrumbList>
                                {trail.map((crumb, index) => (
                                    <Fragment key={`${crumb.label}-${index}`}>
                                        {index > 0 ? <BreadcrumbSeparator /> : null}
                                        <BreadcrumbItem>
                                            {crumb.href === undefined ? (
                                                <BreadcrumbPage>{crumb.label}</BreadcrumbPage>
                                            ) : (
                                                <BreadcrumbLink asChild>
                                                    <Link href={crumb.href}>{crumb.label}</Link>
                                                </BreadcrumbLink>
                                            )}
                                        </BreadcrumbItem>
                                    </Fragment>
                                ))}
                            </BreadcrumbList>
                        </Breadcrumb>

                        <div className="ms-auto">
                            <StorePicker />
                        </div>
                    </header>

                    {/* A div, not a main: SidebarInset is the main landmark already, and a page
                        with two of them tells a screen reader there are two. */}
                    <div className="flex-1 px-4 py-6 lg:px-8">
                        <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <h1 className="text-xl font-semibold text-ink">{title}</h1>
                                {subtitle ? <p className="text-sm text-ink-muted">{subtitle}</p> : null}
                            </div>
                            {action}
                        </div>

                        {children}
                    </div>
                </SidebarInset>
            </SidebarProvider>

            <SyncDocument />
            <Toasts />
        </>
    );
}

/**
 * The area this page belongs to, taken from the menu the person was actually offered.
 *
 * A screen sitting on its own menu entry needs nothing in front of its own title, so it gets
 * nothing: a trail that reads "Staff / Staff" tells somebody less than no trail at all.
 */
function defaultTrail(menu: SharedProps['menu'], here: string): Crumb[] {
    for (const group of menu) {
        for (const entry of group.entries) {
            if (here.startsWith(`${entry.href}/`)) {
                return [{ label: entry.label, href: entry.href }];
            }
        }
    }

    return [];
}
