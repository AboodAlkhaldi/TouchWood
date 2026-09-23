import type { ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { ThemeToggle } from '@/components/Preferences';
import { Logo } from '@/components/Logo';
import type { SharedProps } from '@/types/page';

/*
| The shop's frame (frontend.md §2.3).
|
| Built in step 1 so that every decision the foundation makes - the theme, the direction, the words,
| the links - is proved in both areas rather than only in the panel. Its own screens arrive in
| step 4.
|
| The storefront carries the store and the language in the URL (/sa/ar/...), which is why there is no
| language toggle here: changing it is a different address, and the link is built where the page
| knows both halves.
*/

type Props = {
    title: string;
    children: ReactNode;
};

export function StorefrontLayout({ title, children }: Props) {
    const { store } = usePage<SharedProps>().props;

    return (
        <>
            <Head title={title} />

            <div className="flex min-h-screen flex-col bg-page text-ink">
                <header className="border-b border-line bg-surface">
                    <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-4">
                        <div className="flex items-center gap-3">
                            <Logo className="text-brand" />
                            <span className="text-sm font-semibold">TouchWood</span>
                        </div>

                        <div className="flex items-center gap-3">
                            {store?.current ? (
                                <span className="text-sm text-ink-muted">{store.current.name}</span>
                            ) : null}
                            <ThemeToggle />
                        </div>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{children}</main>

                <footer className="border-t border-line bg-surface">
                    <div className="mx-auto w-full max-w-6xl px-4 py-6 text-xs text-ink-muted">
                        TouchWood
                    </div>
                </footer>
            </div>

            <SyncDocument />
            <Toasts />
        </>
    );
}
