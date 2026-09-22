import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { LanguageToggle, ThemeToggle } from '@/components/Preferences';
import { Logo } from '@/components/Logo';

/*
| The shell every sign-in screen sits in (frontend.md §2, A1-A8).
|
| Two columns on a wide screen, as the design has it: the form on one side, the brand on the other.
| On a phone the brand side is dropped rather than shrunk - a decorative half column is worth nothing
| at 375px, and the form is the whole point of the page.
|
| Nobody is signed in here, so there is no menu, no store and no person block. The language and theme
| toggles are on the page itself, because a person who cannot read the interface has to be able to
| change it before they can sign in.
*/

type Props = {
    title: string;
    subtitle?: string;
    children: ReactNode;
};

export function SignInLayout({ title, subtitle, children }: Props) {
    return (
        <>
            <Head title={title} />

            <div className="grid min-h-screen bg-surface lg:grid-cols-2">
                <div className="flex flex-col justify-center gap-7 px-6 py-12 sm:px-12 lg:px-[8vw]">
                    <div className="flex items-center justify-between gap-4">
                        <Logo className="text-brand" />
                        <div className="flex items-center gap-2">
                            <LanguageToggle />
                            <ThemeToggle />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <h1 className="text-2xl font-semibold text-ink">{title}</h1>
                        {subtitle ? <p className="text-sm text-ink-muted">{subtitle}</p> : null}
                    </div>

                    <div className="grid max-w-md gap-5">{children}</div>
                </div>

                {/* Decoration only, and never read out: everything it carries is said in words on
                    the other side. */}
                <div
                    aria-hidden="true"
                    className="hidden bg-accent-soft lg:flex lg:items-center lg:justify-center"
                >
                    <Logo className="size-40 text-brand/25" />
                </div>
            </div>

            <SyncDocument />
            <Toasts />
        </>
    );
}
