import { Link, usePage } from '@inertiajs/react';
import { shopHref } from '@/components/content/ContentLink';
import type { Block } from '@/components/content/marks';
import { ContentBlocks } from '@/components/shop/ContentBlocks';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { ShopDocumentPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| H2 a page, H4 a blog post (content.md §1.11, §1.12, §4.5). The design has none of them: drawn from its look
| (frontend.md §2.1), to be shown to the owner (plan P4). One reading column: the title, a post's date (Latin digits) and
| cover, then the body from its blocks (§1.2, the same renderer as the editor's preview), then a post's products as the
| shop's cards, only those this shopper can order now. A project has its own page, Project.tsx (flags-2026-11.md S4.3).
*/

export default function Document(props: ShopDocumentPageData) {
    const t = useTranslator();
    const link = useLink();
    const { shop, locale } = usePage<SharedProps>().props;
    const store = shop?.code ?? '';
    const products = props.products as unknown as ShopCardData[];
    const facts = useCardFacts(props.cardFacts, products[0]?.productId);
    const images = props.images as Record<number, { src: string | null; width: number | null; height: number | null }>;
    const date = props.publishedAt === null ? null : new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'long', timeZone: shop?.timezone }).format(new Date(props.publishedAt));
    const back = props.kind === 'post' ? { href: link('storefront.content.blog'), label: t('content::shop.post.back') } : null;

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
                    {date ? (
                        <p className="text-copy-14 text-ink-muted" data-test="post-date">
                            {t('content::shop.post.published', { date })}
                        </p>
                    ) : null}
                    {props.summary ? <p className="text-copy-16 text-ink-muted">{props.summary}</p> : null}
                </header>

                {props.cover ? <ShopPhoto photo={props.cover as PhotoSizes} size="detail" alt={props.coverAlt ?? ''} eager className="aspect-[3/2] w-full rounded-lg" /> : null}

                <ContentBlocks blocks={props.blocks as unknown as Block[]} image={(ref) => images[ref] ?? null} href={(to) => shopHref(to, store, locale)?.href ?? null} />
            </article>

            {products.length > 0 ? (
                <section className="mt-10 grid gap-4" data-test="content-products">
                    <h2 className="text-heading-20 text-ink">{t('content::shop.post.products')}</h2>
                    <ProductCards facts={facts} cards={products} />
                </section>
            ) : null}
        </StorefrontLayout>
    );
}
