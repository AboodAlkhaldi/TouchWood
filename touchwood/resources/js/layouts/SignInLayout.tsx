import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Toasts } from '@/components/Toasts';
import { SyncDocument } from '@/components/SyncDocument';
import { LanguageToggle } from '@/components/Preferences';
import { Logo } from '@/components/Logo';

/*
| The shell every sign-in screen sits in (frontend.md §2, A1-A8): shadcn's `login-02` block, as it
| writes it (§1.11) - the form on one side, the brand's half on the other.
|
| On a phone the brand's half is dropped rather than shrunk, as the block does: a decorative half
| column is worth nothing at 375px, and the form is the whole point of the page.
|
| Nobody is signed in here, so there is no menu, no store and no person block. The language button
| is on the page itself, because a person who cannot read the interface has to be able to change it
| before they can sign in (owner's #3). The theme switch is not here: it sits once, in the panel's
| person menu (owner, 2026-10-02), and a first visit follows the device anyway.
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

            <div className="grid min-h-svh bg-background lg:grid-cols-2">
                <div className="flex flex-col gap-4 p-6 md:p-10">
                    <div className="flex items-center justify-between gap-2">
                        <span className="flex items-center gap-2 font-medium">
                            {/* Cream on a light page, navy on a dark one (owner, 2026-10-04). */}
                            <Logo tone="auto" decorative className="size-7" />
                            TouchWood
                        </span>
                        <LanguageToggle to="/admin/preferences" />
                    </div>

                    <div className="flex flex-1 items-center justify-center">
                        <div className="flex w-full max-w-xs flex-col gap-6">
                            <div className="flex flex-col items-center gap-1 text-center">
                                <h1 className="text-heading-24 text-foreground">{title}</h1>
                                {subtitle ? <p className="text-copy-14 text-balance text-muted-foreground">{subtitle}</p> : null}
                            </div>

                            {children}
                        </div>
                    </div>
                </div>

                {/* Decoration only, and never read out: everything it carries is said in words on
                    the other side. The block's photograph is our mark, until there is one. */}
                <div aria-hidden="true" className="relative hidden bg-muted lg:flex lg:items-center lg:justify-center">
                    <Logo decorative className="size-40 text-primary opacity-25" />
                </div>
            </div>

            <SyncDocument />
            <Toasts />
        </>
    );
}
