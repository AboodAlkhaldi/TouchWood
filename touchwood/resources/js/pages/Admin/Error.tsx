import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';

/*
| The admin panel's error page (frontend.md §2.6): a page somebody signed in went to and could not have - a
| read that is not there, a screen not among their jobs - in the panel's own frame, in the page's language.
| The error's translated title is the page's title; under it, shadcn's Empty (Geist's Empty State) says the
| error's own sentence - "This read isn't available." - or, when the error wrote none, its status's; never a
| message written for developers. The way back is Home.
|
| Rendered by app/Http/ProblemDetails, which knows no module, so its data is typed here rather than generated
| from a module's page class (frontend.md §1.6): the three values it sends, already translated.
*/

type Props = {
    /** 403, 404, 409, 413, 415 or 422. */
    status: number;
    title: string;
    detail: string;
};

export default function ErrorPage({ status, title, detail }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout title={title}>
            <Empty className="material-base" data-test="error-page" data-status={status}>
                <EmptyHeader>
                    <EmptyDescription className="text-copy-16 text-ink" data-test="error-sentence">
                        {detail}
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button asChild>
                        <Link href="/admin" data-test="error-back">
                            {t('admin.error.back')}
                        </Link>
                    </Button>
                </EmptyContent>
            </Empty>
        </AdminLayout>
    );
}
