import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| A shop address that is not a page here (catalog.md §4.5): a slug that never existed, a draft, a
| category listing nothing in this store, an inactive brand - answered 404 in the shop's frame, its
| menu and search box still there to go on with.
*/

export default function NotFound() {
    const t = useTranslator();
    const { shop, locale } = usePage<SharedProps>().props;

    return (
        <StorefrontLayout title={t('catalog::shop.not_found.title')}>
            <Empty className="py-16" data-test="not-found">
                <EmptyHeader>
                    <EmptyTitle className="text-heading-24 text-ink">
                        <h1>{t('catalog::shop.not_found.title')}</h1>
                    </EmptyTitle>
                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::shop.not_found.text')}</EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button asChild>
                        <Link href={shop ? `/${shop.code}/${locale}` : '/'}>{t('catalog::shop.not_found.back')}</Link>
                    </Button>
                </EmptyContent>
            </Empty>
        </StorefrontLayout>
    );
}
