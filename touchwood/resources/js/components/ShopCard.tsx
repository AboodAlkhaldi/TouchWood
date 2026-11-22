import type { ReactNode } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/*
| The card every sign-in form in the shop sits in (frontend.md §3.6), as shadcn's `login-01` and
| `signup-01` write it (§1.11): a Card with its title and description in the header and the form in
| the content, one column as wide as the blocks make it. Geist's look on top: its raised material
| and type scale in place of the Card's own border and shadow.
|
| The shop's own header is above it - the country, the language - because these pages are pages of
| the shop and not a separate place (owner, 2026-09-24), so the blocks' full-screen centring is not
| taken. The way on to the other page of the pair ("No account yet? Create Account") is the form's
| own last line, inside the card, as in the blocks.
*/

type Props = {
    title: string;
    subtitle?: ReactNode;
    children: ReactNode;
};

export function ShopCard({ title, subtitle, children }: Props) {
    return (
        <div className="mx-auto w-full max-w-sm py-6">
            <Card className="material-small gap-6 border-0 py-6">
                <CardHeader className="px-6">
                    <CardTitle className="text-heading-24 text-ink">
                        <h1>{title}</h1>
                    </CardTitle>
                    {subtitle === undefined ? null : <CardDescription className="text-copy-14 text-ink-muted">{subtitle}</CardDescription>}
                </CardHeader>
                <CardContent className="px-6">{children}</CardContent>
            </Card>
        </div>
    );
}
