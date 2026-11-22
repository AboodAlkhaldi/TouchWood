import { Fragment } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopLinkData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The path to a shop page (catalog.md §4.5): the store's home, the categories above it, then the page
| itself - shadcn's Breadcrumb.
*/

export function ShopTrail({ path, current }: { path: ShopLinkData[]; current: string }) {
    const t = useTranslator();
    const link = useLink();
    const { shop, locale } = usePage<SharedProps>().props;

    return (
        <Breadcrumb data-test="trail">
            <BreadcrumbList>
                <BreadcrumbItem>
                    <BreadcrumbLink asChild>
                        <Link href={shop ? `/${shop.code}/${locale}` : '/'}>{t('catalog::shop.home')}</Link>
                    </BreadcrumbLink>
                </BreadcrumbItem>
                {path.map((step) => (
                    <Fragment key={step.slug}>
                        <BreadcrumbSeparator />
                        <BreadcrumbItem>
                            <BreadcrumbLink asChild>
                                <Link href={link('storefront.catalog.category', { slug: step.slug })}>{step.name}</Link>
                            </BreadcrumbLink>
                        </BreadcrumbItem>
                    </Fragment>
                ))}
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbPage>{current}</BreadcrumbPage>
                </BreadcrumbItem>
            </BreadcrumbList>
        </Breadcrumb>
    );
}
