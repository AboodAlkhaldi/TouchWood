import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Logo } from '@/components/Logo';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/*
| The small frame of the pages an emailed link opens (ops.md §4.15 O13): confirm, leave and unsubscribe. shadcn's
| Card and our logo, centred; no menus, no cart, no switches. The page's language and direction are the address's,
| shared by the server, and search engines are kept out.
|
| It reads no store and nothing of the shop's frame: a leave or unsubscribe link of a switched-off store must still
| work (§4.11), and there the server sends no shop at all.
*/

type Props = {
    title: string;
    text: string;
    children: ReactNode;
};

export function EmailLinkLayout({ title, text, children }: Props) {
    return (
        <>
            <Head title={title}>
                <meta name="robots" content="noindex" />
            </Head>

            <div className="flex min-h-svh items-center justify-center bg-backdrop p-4 text-ink">
                <main className="flex w-full max-w-sm flex-col items-center gap-6">
                    <span className="flex items-center gap-2 text-heading-16">
                        <Logo tone="auto" decorative className="size-7" />
                        TouchWood
                    </span>

                    <Card className="material-base w-full gap-4 border-0">
                        <CardHeader>
                            <CardTitle className="text-heading-20 text-ink">
                                <h1>{title}</h1>
                            </CardTitle>
                            <CardDescription className="text-copy-14 text-ink-muted">{text}</CardDescription>
                        </CardHeader>
                        <CardContent>{children}</CardContent>
                    </Card>
                </main>
            </div>
        </>
    );
}
