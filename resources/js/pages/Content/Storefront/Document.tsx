import { Link, usePage } from '@inertiajs/react';
import { shopHref } from '@/components/content/ContentLink';
import type { Block } from '@/components/content/marks';
import { ContentBlocks } from '@/components/shop/ContentBlocks';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { ShopDocumentPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| H2 a page, H4 a blog post, H6 a project (content.md §1.11-§1.13, §4.5). The design has none of them: drawn from its
| look (frontend.md §2.1), to be shown to the owner (plan P4). One reading column: the title, a post's date (Latin
| digits) and cover, a project's city and its photos - swiped on a phone - then the body from its blocks (§1.2, the same
| renderer as the editor's preview), then a post's or a project's products as the shop's cards, only those this
| shopper can order now.
*/

export default function Document(props: ShopDocumentPageData) {
    const t = useTranslator();
    const link = useLink();
    const { shop, locale, direction } = usePage<SharedProps>().props;
    const store = shop?.code ?? '';
    const products = props.products as unknown as ShopCardData[];
    const facts = useCardFacts(props.cardFacts, products[0]?.productId);
    const images = props.images as Record<number, { src: string | null; width: number | null; height: number | null }>;
    const photos = props.photos as unknown as PhotoSizes[];
    const date = props.publishedAt === null ? null : new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'long', timeZone: shop?.timezone }).format(new Date(props.publishedAt));
    const back = props.kind === 'post' ? { href: link('storefront.content.blog'), label: t('content::shop.post.back') } : props.kind === 'project' ? { href: link('storefront.content.projects'), label: t('content::shop.project.back') } : null;

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <article className="mx-auto grid w-full max-w-3xl min-w-0 gap-6" data-test={`content-${props.kind}`}>
                <header className="grid gap-2">
                    {back ? (
                        <Link href={back.href} className="text-label-13 text-ink-muted underline-offset-4 hover:underline">
                            {back.label}
                        </Link>
                    ) : null}
                    {props.category ? (
                        <Link href={link('storefront.content.blog-category', { slug: props.category.slug })} className="text-label-13 text-brand underline-offset-4 hover:underline">
                            {props.category.name}
                        </Link>
                    ) : null}
                    <h1 className="text-heading-32 text-ink">{props.title}</h1>
                    {props.city ? <p className="text-copy-14 text-ink-muted">{props.city}</p> : null}
                    {date ? (
                        <p className="text-copy-14 text-ink-muted" data-test="post-date">
                            {t('content::shop.post.published', { date })}
                        </p>
                    ) : null}
                    {props.summary ? <p className="text-copy-16 text-ink-muted">{props.summary}</p> : null}
                </header>

                {props.cover ? <ShopPhoto photo={props.cover as PhotoSizes} size="detail" alt={props.coverAlt ?? ''} eager className="aspect-[3/2] w-full rounded-lg" /> : null}

                {photos.length > 0 ? (
                    <section aria-label={t('content::shop.project.photos')} data-test="project-photos">
                        <ScrollArea className="w-full" dir={direction}>
                            <ul className="flex snap-x snap-mandatory gap-3 pb-3">
                                {photos.map((photo, index) => (
                                    <li key={index} className="w-[85%] shrink-0 snap-center sm:w-[70%]">
                                        <ShopPhoto photo={photo} size="detail" alt="" eager={index === 0} className="aspect-[3/2] w-full rounded-lg" />
                                    </li>
                                ))}
                            </ul>
                            <ScrollBar orientation="horizontal" />
                        </ScrollArea>
                    </section>
                ) : null}

                <ContentBlocks blocks={props.blocks as unknown as Block[]} image={(ref) => images[ref] ?? null} href={(to) => shopHref(to, store, locale)?.href ?? to} />
            </article>

            {products.length > 0 ? (
                <section className="mt-10 grid gap-4" data-test="content-products">
                    <h2 className="text-heading-20 text-ink">{t(props.kind === 'project' ? 'content::shop.project.products' : 'content::shop.post.products')}</h2>
                    <ProductCards facts={facts} cards={products} />
                </section>
            ) : null}
        </StorefrontLayout>
    );
}
