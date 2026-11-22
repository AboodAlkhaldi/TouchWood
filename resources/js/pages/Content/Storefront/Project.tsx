import { lazy, Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { shopHref } from '@/components/content/ContentLink';
import type { Block } from '@/components/content/marks';
import { ContentBlocks } from '@/components/shop/ContentBlocks';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { VideoTile, type ShopVideo } from '@/components/shop/VideoTile';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { ShopProjectPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| H6, a project's own page (content.md §1.13, §4.5; flags-2026-11.md S4.1-S4.3, the builder's layout from the mockup, which
| has none): the title, then chips - "With <client>", the city, "Client's site" when a link is set; the gallery, the first
| photo large (two thirds) and the next two stacked beside it, every photo opening in the shop's photo viewer, and on a
| phone one large photo and a sideways strip; two columns, the story and - when a client is set - the client's box; then
| Products Used as the shop's cards. A page and a post keep Document.tsx. Its YouTube videos follow the photos in the
| gallery (S4.4): a tile with YouTube's thumbnail, a play mark and the title, which opens the viewer playing it - nothing
| else from YouTube loads before.
*/

// The viewer is fetched when a first photo is opened, not with the page (the script budget, frontend.md §5).
const PhotoViewer = lazy(() => import('@/components/shop/PhotoViewer').then((module) => ({ default: module.PhotoViewer })));

type Client = { name: string | null; logo: PhotoSizes | null; link: string | null };

export default function Project(props: ShopProjectPageData) {
    const t = useTranslator();
    const link = useLink();
    const { shop, locale } = usePage<SharedProps>().props;
    const store = shop?.code ?? '';
    const products = props.products as unknown as ShopCardData[];
    const facts = useCardFacts(props.cardFacts, products[0]?.productId);
    const images = props.images as Record<number, { src: string | null; width: number | null; height: number | null }>;
    const photos = props.photos as unknown as PhotoSizes[];
    const videos = (props.videos ?? []) as ShopVideo[];
    const client = props.client as Client | null;

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <article className="grid w-full min-w-0 grid-cols-1 gap-8" data-test="content-project">
                <header className="grid grid-cols-1 gap-3">
                    <Link href={link('storefront.content.projects')} className="text-label-13 text-ink-muted underline-offset-4 hover:underline">
                        {t('content::shop.project.back')}
                    </Link>
                    <h1 className="text-heading-32 text-ink">{props.title}</h1>
                    {props.clientName || props.city || client?.link ? (
                        <ul className="flex flex-wrap items-center gap-2" data-test="project-chips">
                            {props.clientName ? (
                                <li className="min-w-0 max-w-full">
                                    <Badge variant="secondary" className="max-w-full text-label-13 break-words whitespace-normal" data-test="chip-client">
                                        {t('content::shop.project.with', { client: props.clientName })}
                                    </Badge>
                                </li>
                            ) : null}
                            {props.city ? (
                                <li className="min-w-0 max-w-full">
                                    <Badge variant="secondary" className="max-w-full text-label-13 break-words whitespace-normal" data-test="chip-city">
                                        {props.city}
                                    </Badge>
                                </li>
                            ) : null}
                            {client?.link ? (
                                <li>
                                    <Badge asChild variant="outline" className="text-label-13">
                                        <a href={client.link} target="_blank" rel="noopener noreferrer" data-test="chip-link">
                                            {t('content::shop.project.client_site')}
                                            <ArrowUpRight aria-hidden="true" className="rtl:-scale-x-100" />
                                        </a>
                                    </Badge>
                                </li>
                            ) : null}
                        </ul>
                    ) : null}
                </header>

                <Gallery photos={photos} videos={videos} />

                <div className={client === null ? 'grid min-w-0 grid-cols-1' : 'grid min-w-0 grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]'} data-test="project-columns">
                    <div className="max-w-3xl min-w-0" data-test="project-story">
                        <ContentBlocks blocks={props.blocks as unknown as Block[]} image={(ref) => images[ref] ?? null} href={(to) => shopHref(to, store, locale)?.href ?? null} />
                    </div>
                    {client === null ? null : <ClientBox client={client} />}
                </div>

                {products.length > 0 ? (
                    <section className="grid gap-4" data-test="content-products">
                        <h2 className="text-heading-20 text-ink">{t('content::shop.project.products')}</h2>
                        <ProductCards facts={facts} cards={products} />
                    </section>
                ) : null}
            </article>
        </StorefrontLayout>
    );
}

/** The client's box (S4.1): the logo, the name and a link to the client's site, each only when it is set. */
function ClientBox({ client }: { client: Client }) {
    const t = useTranslator();

    return (
        <aside className="min-w-0 content-start" aria-label={t('content::shop.project.client_box')} data-test="client-box">
            <Card>
                <CardContent className="grid grid-cols-1 gap-3">
                    <span className="text-label-13 text-ink-muted">{t('content::shop.project.client_box')}</span>
                    {client.logo ? <ShopPhoto photo={client.logo} size="card" alt={client.name ?? ''} className="h-20 w-auto max-w-full self-start object-contain" /> : null}
                    {client.name ? <span className="text-heading-16 break-words text-ink">{client.name}</span> : null}
                    {client.link ? (
                        <a href={client.link} target="_blank" rel="noopener noreferrer" className="inline-flex w-fit items-center gap-1 text-label-14 text-brand underline-offset-4 hover:underline" data-test="client-link">
                            {t('content::shop.project.client_site')}
                            <ArrowUpRight aria-hidden="true" className="size-4 rtl:-scale-x-100" />
                        </a>
                    ) : null}
                </CardContent>
            </Card>
        </aside>
    );
}

/**
 * The gallery (S4.3): from a tablet up the first photo or video large, two thirds wide, and the next two stacked beside
 * it, the third carrying how many more there are; on a phone one large and a strip to swipe. The videos come after the
 * photos (S4.4), each a tile with YouTube's thumbnail, a play mark and its title. Every photo opens in the viewer at its
 * own place, and every video plays there.
 */
function Gallery({ photos, videos }: { photos: PhotoSizes[]; videos: ShopVideo[] }) {
    const t = useTranslator();
    const [open, setOpen] = useState<{ index: number; play: boolean } | null>(null);
    // The tile that opened the viewer: focus goes back to it on close (the viewer unmounts, so Radix cannot).
    const opener = useRef<HTMLElement | null>(null);
    const total = photos.length + videos.length;
    const show = (index: number, tile: HTMLElement) => {
        opener.current = tile;
        setOpen({ index, play: index >= photos.length });
    };

    useEffect(() => {
        if (open === null && opener.current !== null) {
            const tile = opener.current;
            opener.current = null;
            tile.focus();
        }
    }, [open]);

    if (total === 0) {
        return null;
    }

    const more = total - 3;
    // A tile of the gallery: a photo's button, or a video's tile, which opens the viewer playing it.
    const tile = (index: number, size: 'detail' | 'card' | 'thumb', className: string, test?: string, extra?: ReactNode) => {
        const video = videos[index - photos.length];

        return video ? (
            <VideoTile video={video} label={t('content::shop.project.video_play')} onPress={(pressed) => show(index, pressed)} small={size === 'thumb'} className={className} test={test}>
                {extra}
            </VideoTile>
        ) : (
            <button type="button" aria-label={t('content::shop.project.photo_open', { number: index + 1, total })} onClick={(event) => show(index, event.currentTarget)} className={className} data-test={test}>
                <ShopPhoto photo={photos[index] ?? null} size={size} alt="" eager={index === 0} className="absolute inset-0 size-full" />
                {extra}
            </button>
        );
    };

    return (
        <section aria-label={t('content::shop.project.photos')} data-test="project-photos">
            <div className={total === 1 ? 'grid grid-cols-1 gap-3' : 'grid grid-cols-1 gap-3 sm:grid-cols-3'}>
                {tile(0, 'detail', total === 1 ? 'relative block aspect-[3/2] w-full overflow-hidden rounded-lg sm:aspect-[2/1]' : 'relative block aspect-[3/2] w-full overflow-hidden rounded-lg sm:col-span-2', 'gallery-main')}
                {total > 1 ? (
                    <ul className={total === 2 ? 'grid grid-rows-1 gap-3 max-sm:hidden' : 'grid grid-rows-2 gap-3 max-sm:hidden'} data-test="gallery-stack">
                        {[1, 2].filter((index) => index < total).map((index) => (
                            <li key={index} className="relative min-h-0">
                                {tile(
                                    index,
                                    'card',
                                    'absolute inset-0 block overflow-hidden rounded-lg',
                                    'gallery-tile',
                                    index === 2 && more > 0 ? (
                                        <Badge className="tw-figure absolute end-2 bottom-2 bg-black/60 text-white" data-test="gallery-more">
                                            {`+${more}`}
                                        </Badge>
                                    ) : null,
                                )}
                            </li>
                        ))}
                    </ul>
                ) : null}
                {/* A phone's strip: a plain row that scrolls sideways, as the product page's (S4.4 left room for videos this way). */}
                {total > 1 ? (
                    <ul className="flex gap-2 overflow-x-auto pb-3 sm:hidden" data-test="gallery-strip">
                        {Array.from({ length: total - 1 }, (_, at) => (
                            <li key={at} className="w-28 shrink-0">
                                {tile(at + 1, 'thumb', 'relative block aspect-[3/2] w-full overflow-hidden rounded-md', videos[at + 1 - photos.length] ? 'strip-video' : undefined)}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
            {open === null ? null : (
                <Suspense fallback={null}>
                    <PhotoViewer items={[...photos.map((photo) => ({ photo })), ...videos.map((video) => ({ video }))]} index={open.index} play={open.play} onIndex={(index) => setOpen(index === null ? null : { index, play: false })} title={t('content::shop.project.viewer_title')} />
                </Suspense>
            )}
        </section>
    );
}
