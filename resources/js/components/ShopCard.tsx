import type { ReactNode } from 'react';

/*
| The card every form in the shop sits in (frontend.md §3.6), as one of Geist's raised materials
| (1.10).
|
| The shop's own header is above it - the country, the language, the theme - because these pages
| are pages of the shop and not a separate place (owner, 2026-09-24). What this adds is only the
| card: one column, narrow enough to read, centred on the page.
*/

type Props = {
    title: string;
    subtitle?: string;
    children: ReactNode;
    /** Under the card rather than inside it: the way on to the other page of the pair. */
    footer?: ReactNode;
};

export function ShopCard({ title, subtitle, children, footer }: Props) {
    return (
        <div className="mx-auto grid w-full max-w-md gap-4 py-6">
            <div className="material-small grid gap-5 p-6">
                <div className="grid gap-1.5">
                    <h1 className="text-heading-24 text-ink">{title}</h1>
                    {subtitle ? <p className="text-copy-14 text-ink-muted">{subtitle}</p> : null}
                </div>

                {children}
            </div>

            {footer ? <div className="text-center text-copy-14 text-ink-muted">{footer}</div> : null}
        </div>
    );
}
