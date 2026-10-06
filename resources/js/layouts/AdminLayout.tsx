import { Fragment, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Store as StoreIcon } from 'lucide-react';
import { AppSidebar } from '@/components/AppSidebar';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The admin panel's frame (frontend.md §2.2): shadcn's `sidebar-07` page, as the block writes it
| (§1.11) - the sidebar, and a top bar with its trigger and the trail back.
|
| The store being worked in is chosen in the sidebar's header, which is where the block puts its
| switcher. The panel carries no store in its URLs: the store is remembered on the account, and a
| screen that is store-free simply ignores it.
|
| The page's own title block - a big title, one line under it, the page's main action at the top
| end - is ours, kept by the owner (§1.11 #1, 2026-10-02), set in Geist's type.
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
    const { menu, sidebarOpen, store } = page.props;
    const here = page.url.split('?')[0] ?? page.url;
    const t = useTranslator();

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

                <SidebarInset>
                    <header className="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
                        <div className="flex items-center gap-2 px-4">
                            {/* shadcn writes its name in English; the page's language reads it. */}
                            <SidebarTrigger className="-ms-1" aria-label={t('admin.sidebar_toggle')} />
                            <Separator orientation="vertical" className="me-2 data-[orientation=vertical]:h-4" />
                            {/* Its landmark named in the page's language, not shadcn's English. */}
                            <Breadcrumb aria-label={t('ui.breadcrumbs')}>
                                <BreadcrumbList>
                                    {trail.map((crumb, index) => {
                                        const last = index === trail.length - 1;

                                        return (
                                            <Fragment key={`${index}-${crumb.label}`}>
                                                {/* On a phone only the page itself shows, as the block does. */}
                                                <BreadcrumbItem className={last ? undefined : 'hidden md:block'}>
                                                    {last || crumb.href === undefined ? (
                                                        <BreadcrumbPage>{crumb.label}</BreadcrumbPage>
                                                    ) : (
                                                        <BreadcrumbLink asChild>
                                                            <Link href={crumb.href}>{crumb.label}</Link>
                                                        </BreadcrumbLink>
                                                    )}
                                                </BreadcrumbItem>
                                                {/* Pointing onward in the page's own direction: in
                                                    Arabic the trail reads right to left. */}
                                                {last ? null : (
                                                    <BreadcrumbSeparator className="hidden md:block">
                                                        <ChevronRight className="rtl:rotate-180" />
                                                    </BreadcrumbSeparator>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </BreadcrumbList>
                            </Breadcrumb>
                        </div>

                        {/* View Store (frontend.md §2.2; access.md §1.11): the shop of the store
                            being worked in, as this staff member - a post, since it opens a pass;
                            shown only while the panel works in a store. The address is written out,
                            as the store switcher's is: the route helper would add its weight to
                            every admin page (frontend.md §5's page budget). */}
                        {store?.current ? (
                            <div className="ms-auto px-4">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    data-test="view-store"
                                    onClick={() => router.post('/admin/staff-view')}
                                >
                                    <StoreIcon aria-hidden="true" />
                                    {t('admin.staff_view.open')}
                                </Button>
                            </div>
                        ) : null}
                    </header>

                    {/* A div, not a main: SidebarInset is the main landmark already, and a page
                        with two of them tells a screen reader there are two. */}
                    <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <h1 className="text-heading-24 text-foreground">{title}</h1>
                                {subtitle ? <p className="text-copy-14 text-muted-foreground">{subtitle}</p> : null}
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
