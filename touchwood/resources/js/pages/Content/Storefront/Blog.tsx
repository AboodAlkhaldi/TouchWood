import { Link, usePage } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopBlogPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| H3, the blog (content.md §1.12, §4.5), and one of its categories: cards of cover, title, summary and date, newest
| first, 12 then Show More, with the categories that hold a post as chips. "No posts yet." while there is none. The design
| has no blog: drawn from its look (frontend.md §2.1; plan P4).
*/

type Post = { id: string; title: string; slug: string; summary: string | null; cover: PhotoSizes | null; coverAlt: string | null; publishedAt: string; category: string | null };

export default function Blog(props: ShopBlogPageData) {
    const t = useTranslator();
    const link = useLink();
    const { shop, locale } = usePage<SharedProps>().props;
    const list = useLoadMore<Post>(props.posts as unknown as Post[], (post) => post.id);
    const dates = new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'medium', timeZone: shop?.timezone });
    const here = props.categorySlug === null ? link('storefront.content.blog') : link('storefront.content.blog-category', { slug: props.categorySlug });

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <div className="grid gap-6" data-test="blog">
                <h1 className="text-heading-32 text-ink">{props.categoryName ?? t('content::shop.blog.title')}</h1>

                {props.categories.length > 0 ? (
                    <nav aria-label={t('content::shop.blog.categories')} className="-mx-4 overflow-x-auto px-4">
                        <ul className="flex gap-2">
                            <li>
                                <Badge asChild variant={props.categorySlug === null ? 'default' : 'outline'} className="h-8 px-3">
                                    <Link href={link('storefront.content.blog')} aria-current={props.categorySlug === null ? 'page' : undefined}>
                                        {t('content::shop.blog.all')}
                                    </Link>
                                </Badge>
                            </li>
                            {props.categories.map((category) => (
                                <li key={category.slug}>
                                    <Badge asChild variant={props.categorySlug === category.slug ? 'default' : 'outline'} className="h-8 px-3">
                                        <Link href={link('storefront.content.blog-category', { slug: category.slug })} aria-current={props.categorySlug === category.slug ? 'page' : undefined} data-test="blog-chip">
                                            {category.name}
                                        </Link>
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </nav>
                ) : null}

                {list.rows.length === 0 ? (
                    <Empty className="py-12">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('content::shop.blog.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {list.rows.map((post) => (
                            <li key={post.id}>
                                <Link href={link('storefront.content.post', { slug: post.slug })} className="material-small group grid h-full content-start gap-3 overflow-hidden bg-surface p-3 hover:shadow-[0_0_0_1px_var(--tw-line-strong)]" data-test="blog-card">
                                    <ShopPhoto photo={post.cover} size="card" alt={post.coverAlt ?? ''} className="aspect-[3/2] w-full rounded-md" />
                                    {post.category ? <span className="text-label-12 text-brand">{post.category}</span> : null}
                                    <span className="text-heading-16 text-ink group-hover:underline">{post.title}</span>
                                    {post.summary ? <span className="line-clamp-2 text-copy-14 text-ink-muted">{post.summary}</span> : null}
                                    <time dateTime={post.publishedAt} className="text-copy-13 text-ink-muted">
                                        {dates.format(new Date(post.publishedAt))}
                                    </time>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {props.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(here, { after: props.next ?? '' }, ['posts', 'next'])} /> : null}
            </div>
        </StorefrontLayout>
    );
}
