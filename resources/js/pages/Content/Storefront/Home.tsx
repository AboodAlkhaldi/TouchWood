import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ContentLink } from '@/components/content/ContentLink';
import { promiseIcon } from '@/components/content/promise-icons';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { Button } from '@/components/ui/button';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { ShopHomePageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| H1, a store's home (content.md §1.4, §4.5), to the design's home: the sections staff show, in their order - the hero
| (on a phone the swiped banners instead, with the design's dots), the promises row, the categories as tiles, the best
| sellers, the newest project with two guides, the two audiences, the Points band and the client logos. A section with
| nothing to show draws nothing. A live theme dresses the promises, Points and logos bands with its artwork (the
| server's custom properties) and may give their words.
|
| **The first image is fetched at once, never lazily**, and each picture only at its own width: the desktop hero's file
| never reaches a phone, nor the phone's banner a desktop (a `<source>` of an empty image for the other width).
*/

type Words = Record<string, unknown>;
type ButtonWords = { text: string | null; link: string | null } | null;
type Section = { type: string; words: Words; project?: Document; guides?: Document[]; logos?: Logo[] };
type Document = { title: string; slug: string; line: string | null; image: PhotoSizes | [] };
type Logo = { name: string | null; link: string | null; image: PhotoSizes };
type Banner = { id: string; link: string | null; alt: string | null; image: PhotoSizes };
type Tile = { name: string; slug: string; photo: PhotoSizes | null };

/** An image no width asks for: the `<source>` that keeps a picture's file from the other width. */
const NOTHING = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

const text = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null);

export default function Home(props: ShopHomePageData) {
    const { shop, locale } = usePage<SharedProps>().props;
    const store = shop?.code ?? '';
    const sections = props.sections as unknown as Section[];
    const facts = useCardFacts(props.cardFacts, (props.bestSellers as unknown as ShopCardData[])[0]?.productId);

    const draw = (section: Section): ReactNode => {
        switch (section.type) {
            case 'HERO':
                return <Hero key="hero" words={section.words} banner={props.heroBanner as Banner | null} phone={props.phoneBanners as unknown as Banner[]} store={store} locale={locale} />;
            case 'PROMISES':
                return <Promises key="promises" words={section.words} theme={props.themeParts} />;
            case 'CATEGORIES':
                return <Tiles key="categories" heading={text(section.words.heading)} tiles={props.categoryTiles as unknown as Tile[]} />;
            case 'BEST_SELLERS':
                return (
                    <Band key="best" heading={text(section.words.heading)} test="home-best-sellers">
                        <ProductCards facts={facts} cards={props.bestSellers as unknown as ShopCardData[]} />
                    </Band>
                );
            case 'PROJECTS':
                return <Projects key="projects" heading={text(section.words.heading)} project={section.project} guides={section.guides ?? []} />;
            case 'AUDIENCE':
                return <Audience key="audience" words={section.words} store={store} locale={locale} />;
            case 'POINTS':
                return props.points === null ? null : <Points key="points" words={section.words} points={props.points} theme={props.themeParts} locale={locale} />;
            case 'CLIENT_LOGOS':
                return <Logos key="logos" heading={props.themeParts.logosHeading ?? text(section.words.heading)} logos={section.logos ?? []} art={props.themeParts.logosArt} store={store} locale={locale} />;
            default:
                return null;
        }
    };

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <div className="grid gap-10 sm:gap-16" data-test="home">
                {sections.map(draw)}
            </div>
        </StorefrontLayout>
    );
}

/** A section's frame: its heading, then what it holds. */
function Band({ heading, children, test, aside }: { heading: string | null; children: ReactNode; test: string; aside?: ReactNode }) {
    return (
        <section className="grid min-w-0 gap-4" data-test={test}>
            {heading || aside ? (
                <div className="flex items-center justify-between gap-3">
                    {heading ? <h2 className="text-heading-20 text-ink sm:text-heading-24">{heading}</h2> : <span />}
                    {aside}
                </div>
            ) : null}
            {children}
        </section>
    );
}

/** A picture drawn at one width only: at the other, its `<source>` is an empty image, so its file is never fetched. */
function WidthPicture({ photo, alt, wide, first, className }: { photo: PhotoSizes; alt: string; wide: boolean; first: boolean; className: string }) {
    const formats = photo.detail ?? photo.zoom ?? photo.card ?? {};
    const src = formats.jpg ?? formats.webp ?? formats.avif;
    const media = wide ? '(max-width: 640px)' : '(min-width: 641px)';

    if (src === undefined) {
        return null;
    }

    return (
        <picture>
            <source media={media} srcSet={NOTHING} />
            {formats.avif ? <source type="image/avif" srcSet={formats.avif} /> : null}
            {formats.webp ? <source type="image/webp" srcSet={formats.webp} /> : null}
            <img src={src} alt={alt} loading={first ? 'eager' : 'lazy'} decoding="async" {...(first ? { fetchPriority: 'high' as const } : {})} className={className} />
        </picture>
    );
}

function Hero({ words, banner, phone, store, locale }: { words: Words; banner: Banner | null; phone: Banner[]; store: string; locale: string }) {
    const t = useTranslator();
    const main = words.main as ButtonWords;
    const second = words.second as ButtonWords;
    const facts = Array.isArray(words.facts) ? (words.facts as unknown[]).map(text).filter((fact): fact is string => fact !== null) : [];
    const title = text(words.title);

    return (
        <section className="grid gap-4" data-test="home-hero">
            {/* A phone: the swiped banners (§1.6 HOME_PHONE), the design's dots under them. */}
            {phone.length > 0 ? (
                <div className="grid gap-2 sm:hidden">
                    <ul className="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 [scrollbar-width:none]" aria-label={t('content::shop.home.banners')} data-test="home-phone-banners">
                        {phone.map((item, index) => (
                            <li key={item.id} className="w-[calc(100%-1rem)] shrink-0 snap-center">
                                <ContentLink link={item.link} store={store} locale={locale} className="block overflow-hidden rounded-lg">
                                    <WidthPicture photo={item.image} alt={item.alt ?? t('content::shop.home.banner_of', { number: figure(locale, index + 1), count: figure(locale, phone.length) })} wide={false} first={index === 0} className="aspect-[2/1] w-full object-cover" />
                                </ContentLink>
                            </li>
                        ))}
                    </ul>
                    {phone.length > 1 ? (
                        <div className="flex justify-center gap-1.5" aria-hidden="true">
                            {phone.map((item) => (
                                <i key={item.id} className="block h-1.5 w-1.5 rounded-full bg-line-strong" />
                            ))}
                        </div>
                    ) : null}
                </div>
            ) : null}

            {/* From a tablet up: the hero's words beside its artwork. */}
            {title || banner ? (
                <div className="hidden overflow-hidden rounded-xl bg-surface-sunken sm:grid sm:grid-cols-2 sm:items-center">
                    <div className="grid content-center gap-4 p-8 lg:p-12">
                        {text(words.eyebrow) ? <span className="text-label-13 text-ink-muted">{text(words.eyebrow)}</span> : null}
                        {title ? <h1 className="text-heading-32 text-ink lg:text-heading-48">{title}</h1> : null}
                        {text(words.line) ? <p className="text-copy-16 text-ink-muted">{text(words.line)}</p> : null}
                        <div className="flex flex-wrap gap-3">
                            {main?.text && main.link ? (
                                <Button asChild size="lg">
                                    <ContentLink link={main.link} store={store} locale={locale}>{main.text}</ContentLink>
                                </Button>
                            ) : null}
                            {second?.text && second.link ? (
                                <Button asChild size="lg" variant="outline">
                                    <ContentLink link={second.link} store={store} locale={locale}>{second.text}</ContentLink>
                                </Button>
                            ) : null}
                        </div>
                        {facts.length > 0 ? (
                            <ul className="flex flex-wrap gap-x-6 gap-y-2 border-t border-line pt-4">
                                {facts.map((fact) => (
                                    <li key={fact} className="text-label-13 text-ink">
                                        {fact}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                    {banner ? (
                        <ContentLink link={banner.link} store={store} locale={locale} className="block h-full min-h-[420px]">
                            <WidthPicture photo={banner.image} alt={banner.alt ?? ''} wide first className="h-full min-h-[420px] w-full object-cover" />
                        </ContentLink>
                    ) : null}
                </div>
            ) : null}
            {/* A phone with no banner still says what the store is. */}
            {phone.length === 0 && title ? <h1 className="text-heading-24 text-ink sm:hidden">{title}</h1> : null}
        </section>
    );
}

function Promises({ words, theme }: { words: Words; theme: ShopHomePageData['themeParts'] }) {
    const promises = Array.isArray(words.promises) ? (words.promises as { icon: string; title: string | null; line: string | null }[]) : [];

    if (promises.length === 0) {
        return null;
    }

    return (
        <section className={`-mx-4 overflow-x-auto px-4 sm:mx-0 sm:rounded-xl sm:border sm:border-line ${theme.promisesArt ? 'bg-cover bg-center [background-image:var(--tw-theme-promises)]' : ''}`} data-test="home-promises">
            <ul className="flex gap-3 py-1 sm:grid sm:grid-cols-4 sm:gap-0 sm:py-0">
                {promises.map((promise, index) => {
                    const Icon = promiseIcon(promise.icon);
                    // A theme may give each promise its own words (§1.8 part 3).
                    const title = theme.promises[`promise_${index + 1}`] ?? promise.title;

                    return (
                        <li key={index} className="flex shrink-0 items-center gap-3 rounded-full border border-line bg-surface px-3 py-2 sm:rounded-none sm:border-0 sm:bg-transparent sm:p-5">
                            <Icon aria-hidden className="size-5 shrink-0 text-brand" />
                            <span className="grid">
                                <span className="text-label-13 text-ink sm:text-label-14">{title}</span>
                                {promise.line ? <span className="hidden text-copy-13 text-ink-muted sm:block">{promise.line}</span> : null}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function Tiles({ heading, tiles }: { heading: string | null; tiles: Tile[] }) {
    const link = useLink();

    return (
        <Band heading={heading} test="home-categories">
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <li key={tile.slug}>
                        <Link href={link('storefront.catalog.category', { slug: tile.slug })} className="material-small group grid gap-2 overflow-hidden bg-surface p-3 hover:shadow-[0_0_0_1px_var(--tw-line-strong)]" data-test="home-tile">
                            <ShopPhoto photo={tile.photo} size="card" alt="" className="aspect-[4/3] w-full rounded-md" />
                            <span className="truncate text-label-14 text-ink group-hover:underline">{tile.name}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </Band>
    );
}

function Projects({ heading, project, guides }: { heading: string | null; project: Document | undefined; guides: Document[] }) {
    const t = useTranslator();
    const link = useLink();

    if (project === undefined) {
        return null;
    }

    return (
        <Band
            heading={heading}
            test="home-projects"
            aside={
                <Link href={link('storefront.content.projects')} className="text-label-14 text-ink underline-offset-4 hover:underline">
                    {t('content::shop.home.all_projects')}
                </Link>
            }
        >
            <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <Link href={link('storefront.content.project', { slug: project.slug })} className="group relative block overflow-hidden rounded-xl" data-test="home-project">
                    <ShopPhoto photo={Array.isArray(project.image) ? null : project.image} size="detail" alt="" className="aspect-[2/1] w-full" />
                    <span className="absolute inset-x-0 bottom-0 grid gap-1 bg-[var(--tw-overlay)] p-4 text-ink-on-brand">
                        {project.line ? <span className="text-label-13">{project.line}</span> : null}
                        <span className="text-heading-16 group-hover:underline sm:text-heading-20">{project.title}</span>
                    </span>
                </Link>
                {guides.length > 0 ? (
                    <div className="grid content-start gap-3">
                        <span className="text-label-13 text-ink-muted">{t('content::shop.home.guides')}</span>
                        {guides.map((guide) => (
                            <Link key={guide.slug} href={link('storefront.content.post', { slug: guide.slug })} className="group grid grid-cols-[6rem_1fr] items-center gap-3" data-test="home-guide">
                                <ShopPhoto photo={Array.isArray(guide.image) ? null : guide.image} size="card" alt="" className="aspect-[3/1] w-full rounded-md sm:aspect-[4/3]" />
                                <span className="text-label-14 text-ink group-hover:underline">{guide.title}</span>
                            </Link>
                        ))}
                    </div>
                ) : null}
            </div>
        </Band>
    );
}

function Audience({ words, store, locale }: { words: Words; store: string; locale: string }) {
    const blocks = [words.individuals, words.companies] as ({ title: string | null; line: string | null; button: ButtonWords } | undefined)[];

    return (
        <section className="grid gap-4 sm:grid-cols-2" data-test="home-audience">
            {blocks.map((block, index) =>
                block?.title ? (
                    <div key={index} className="grid content-start gap-3 rounded-xl border border-line bg-surface p-6">
                        <h3 className="text-heading-20 text-ink">{block.title}</h3>
                        {block.line ? <p className="text-copy-14 text-ink-muted">{block.line}</p> : null}
                        {block.button?.text && block.button.link ? (
                            <Button asChild variant={index === 0 ? 'default' : 'outline'} className="justify-self-start">
                                <ContentLink link={block.button.link} store={store} locale={locale}>{block.button.text}</ContentLink>
                            </Button>
                        ) : null}
                    </div>
                ) : null,
            )}
        </section>
    );
}

function Points({ words, points, theme, locale }: { words: Words; points: NonNullable<ShopHomePageData['points']>; theme: ShopHomePageData['themeParts']; locale: string }) {
    const t = useTranslator();
    const heading = theme.pointsHeading ?? text(words.heading);

    return (
        <section className={`grid gap-4 rounded-xl border border-line bg-surface-sunken p-6 sm:grid-cols-[3fr_2fr] sm:items-center sm:p-10 ${theme.pointsArt ? 'bg-cover bg-center [background-image:var(--tw-theme-points)]' : ''}`} data-test="home-points">
            <div className="grid gap-2">
                {text(words.small_heading) ? <span className="text-label-13 text-ink-muted">{text(words.small_heading)}</span> : null}
                {heading ? <h2 className="text-heading-24 text-ink">{heading}</h2> : null}
                {text(words.line) ? <p className="text-copy-14 text-ink-muted">{text(words.line)}</p> : null}
            </div>
            {/* The figures are the store's settings, never typed (Q16 (b)). */}
            <ul className="grid gap-3">
                <li className="text-label-14 text-ink" data-test="points-earn">
                    {t('content::shop.home.points_earn', { points: figure(locale, points.earn), currency: points.currency })}
                </li>
                <li className="text-label-14 text-ink" data-test="points-off">
                    {t('content::shop.home.points_off', { points: figure(locale, points.off), currency: points.currency })}
                </li>
            </ul>
        </section>
    );
}

function Logos({ heading, logos, art, store, locale }: { heading: string | null; logos: Logo[]; art: boolean; store: string; locale: string }) {
    return (
        <section className={`-mx-4 grid gap-4 border-y border-line bg-surface-sunken px-4 py-8 ${art ? 'bg-cover bg-center [background-image:var(--tw-theme-logos)]' : ''}`} data-test="home-logos">
            {heading ? <h2 className="text-heading-20 text-ink">{heading}</h2> : null}
            <ul className="flex flex-wrap items-center justify-center gap-6">
                {logos.map((logo, index) => (
                    <li key={index}>
                        <ContentLink link={logo.link} store={store} locale={locale} className="block">
                            <ShopPhoto photo={logo.image} size="card" alt={logo.name ?? ''} className="h-[78px] w-[180px] object-contain" />
                        </ContentLink>
                    </li>
                ))}
            </ul>
        </section>
    );
}
