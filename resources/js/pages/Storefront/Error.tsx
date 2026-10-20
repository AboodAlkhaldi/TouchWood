import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The shop's error page (frontend.md §2.6): a page of a store's shop somebody went to and could not have - an
| address that is no page, say - in the shop's own frame, its header and Catalog's menu still there, in the
| page's language. The error's translated title heads it; under it, shadcn's Empty (Geist's Empty State) says
| the error's own sentence or, when it wrote none, its status's in a shopper's words - "This page isn't
| available." - never a message written for developers. The way back is the store's home.
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
    const { shop, locale } = usePage<SharedProps>().props;

    return (
        <StorefrontLayout title={title}>
            <Empty className="py-16" data-test="error-page" data-status={status}>
                <EmptyHeader>
                    <EmptyTitle className="text-heading-24 text-ink">
                        <h1>{title}</h1>
                    </EmptyTitle>
                    <EmptyDescription className="text-copy-14 text-ink-muted" data-test="error-sentence">
                        {detail}
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button asChild>
                        <Link href={shop ? `/${shop.code}/${locale}` : '/'} data-test="error-back">
                            {t('errors.shop.back')}
                        </Link>
                    </Button>
                </EmptyContent>
            </Empty>
        </StorefrontLayout>
    );
}
