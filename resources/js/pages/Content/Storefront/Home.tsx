import { type ComponentType, type ReactNode, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { type Banner, BannerSlide } from '@/components/content/BannerSlide';
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
| H1, a store's home (content.md §1.4, §4.5), to the design (shop-design.md §3): the sections staff show, in their order -
| **the banners** (news, events, big sales: one list for every width, turning every 5 seconds) with the store's title
| under them, the promises row with the hero's facts, the categories as tiles, the best sellers, the newest project with
| two guides, the two audiences, the Points band and the client logos. Each band brings its own 1400 px box, so its ground
| reaches the screen's edges. A section with nothing to show draws nothing. A live theme dresses the promises, Points
| and logos bands with its artwork (the server's custom properties) and may give their words.
|
| **The first image is fetched at once, never lazily**, and each picture only at its own width: the wide picture's file
| never reaches a phone, nor the phone's a desktop (a `<source>` of an empty image for the other width).
*/

type Words = Record<string, unknown>;
type ButtonWords = { text: string | null; link: string | null } | null;
type Section = { type: string; words: Words; project?: Document; guides?: Document[]; logos?: Logo[] };
type Document = { title: string; slug: string; line: string | null; image: PhotoSizes | [] };
type Logo = { name: string | null; link: string | null; image: PhotoSizes };
type Fact = { figure: string | null; caption: string | null } | string | null;

type Tile = { name: string; slug: string; photo: PhotoSizes | null };

const text = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null);

export default function Home(props: ShopHomePageData) {
    const { shop, locale } = usePage<SharedProps>().props;
    const store = shop?.code ?? '';
    const sections = props.sections as unknown as Section[];
    const facts = useCardFacts(props.cardFacts, (props.bestSellers as unknown as ShopCardData[])[0]?.productId);
    // The hero's facts are drawn in the promises row (shop-design.md §3.1, P3); with no promises row, alone there.
    const heroFacts = factsOf(sections.find((section) => section.type === 'HERO')?.words.facts);
    const hasPromises = sections.some((section) => section.type === 'PROMISES');

    const draw = (section: Section): ReactNode => {
        switch (section.type) {
            case 'HERO':
                return <Hero key="hero" words={section.words} banners={props.banners as unknown as Banner[]} store={store} locale={locale} />;
            case 'PROMISES':
                return <Promises key="promises" words={section.words} facts={heroFacts} theme={props.themeParts} />;
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
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head} bands>
            <div className="grid gap-6 sm:gap-16" data-test="home">
                {sections.map((section) => (section.type === 'HERO' && !hasPromises ? [draw(section), <Promises key="facts" words={{}} facts={heroFacts} theme={props.themeParts} />] : draw(section)))}
            </div>
        </StorefrontLayout>
    );
}

/** The hero's facts, each a figure and its caption (§10.9); one saved before 2026-11-16 is a caption alone. */
function factsOf(value: unknown): { figure: string | null; caption: string }[] {
    return (Array.isArray(value) ? (value as Fact[]) : [])
        .map((fact) => (typeof fact === 'string' ? { figure: null, caption: fact } : { figure: text(fact?.figure), caption: text(fact?.caption) ?? '' }))
        .filter((fact) => fact.caption !== '' || fact.figure !== null);
}

/** A section's frame, in its own 1400 px box: its heading at the design's 28 px, then what it holds. */
function Band({ heading, children, test, aside }: { heading: string | null; children: ReactNode; test: string; aside?: ReactNode }) {
    return (
        <section className="shop-box grid min-w-0 gap-4" data-test={test}>
            {heading || aside ? (
                <div className="flex items-center justify-between gap-3">
                    {heading ? <h2 className="text-heading-20 text-ink sm:text-[28px] sm:leading-tight sm:font-semibold">{heading}</h2> : <span />}
                    {aside}
                </div>
            ) : null}
            {children}
        </section>
    );
}

function Hero({ words, banners, store, locale }: { words: Words; banners: Banner[]; store: string; locale: string }) {
    const title = text(words.title);

    return (
        <section className="grid gap-4 pt-4 sm:pt-6" data-test="home-hero">
            {banners.length > 0 ? <Banners banners={banners} store={store} locale={locale} /> : null}
            {/* The store's title stays the page's H1, a slim line under the banners (option (b); P16: alone with none). */}
            {title ? <h1 className="shop-box text-heading-20 text-ink sm:text-heading-24" data-test="home-title">{title}</h1> : null}
        </section>
    );
}

type BannersView = ComponentType<{ banners: Banner[]; store: string; locale: string }>;

/**
 * The banners (shop-design.md §3.1): the first drawn as it is, by the server too, until the carousel's own file
 * (HomeBanners, on shadcn's Carousel) has loaded after the page shows and turns them (frontend.md §5).
 */
function Banners({ banners, store, locale }: { banners: Banner[]; store: string; locale: string }) {
    const [View, setView] = useState<BannersView | null>(null);

    useEffect(() => {
        if (banners.length > 1) {
            void import('@/components/content/HomeBanners').then((loaded) => setView(() => loaded.default));
        }
    }, [banners.length]);

    if (View !== null) {
        return <View banners={banners} store={store} locale={locale} />;
    }

    const first = banners[0];

    return first === undefined ? null : (
        <div className="shop-box relative max-sm:px-0!" data-test="home-banners">
            <div data-test="home-banner">
                <BannerSlide banner={first} first store={store} locale={locale} />
            </div>
            {/* The dots' room is kept, so nothing moves when the carousel comes. */}
            {banners.length > 1 ? <div className="mt-3 h-1.5" aria-hidden="true" /> : null}
        </div>
    );
}

function Promises({ words, facts, theme }: { words: Words; facts: { figure: string | null; caption: string }[]; theme: ShopHomePageData['themeParts'] }) {
    const promises = Array.isArray(words.promises) ? (words.promises as { icon: string; title: string | null; line: string | null }[]) : [];

    if (promises.length === 0 && facts.length === 0) {
        return null;
    }

    // The design's service strip: edge to edge, a line under it; 4 columns, 2 up to 820 px; the hero's facts one slim row under them.
    return (
        <section className={`border-b border-line ${theme.promisesArt ? 'bg-cover bg-center [background-image:var(--tw-theme-promises)]' : ''}`} data-test="home-promises">
            {promises.length > 0 ? (
                <ul className="shop-box grid grid-cols-2 gap-3 py-4 min-[821px]:grid-cols-4 sm:gap-0 sm:py-2">
                    {promises.map((promise, index) => {
                        const Icon = promiseIcon(promise.icon);
                        // A theme may give each promise its own words (§1.8 part 3).
                        const title = theme.promises[`promise_${index + 1}`] ?? promise.title;

                        return (
                            <li key={index} className="flex items-center gap-3 sm:p-4">
                                <Icon aria-hidden className="size-5 shrink-0 text-accent" />
                                <span className="grid">
                                    <span className="text-label-13 text-ink sm:text-label-14">{title}</span>
                                    {promise.line ? <span className="hidden text-copy-13 text-ink-muted sm:block">{promise.line}</span> : null}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
            {facts.length > 0 ? (
                <ul className="shop-box flex flex-wrap gap-x-8 gap-y-2 border-t border-line py-3" data-test="home-facts">
                    {facts.map((fact) => (
                        <li key={`${fact.figure ?? ''}${fact.caption}`} className="flex items-baseline gap-2">
                            {fact.figure ? <span className="text-label-14 font-semibold text-ink">{fact.figure}</span> : null}
                            <span className="text-copy-13 text-ink-muted">{fact.caption}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

function Tiles({ heading, tiles }: { heading: string | null; tiles: Tile[] }) {
    const link = useLink();

    return (
        <Band heading={heading} test="home-categories">
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {tiles.map((tile) => (
                    <li key={tile.slug}>
                        <Link href={link('storefront.catalog.category', { slug: tile.slug })} className="material-small group grid overflow-hidden bg-surface hover:shadow-[0_0_0_1px_var(--tw-line-strong)]" data-test="home-tile">
                            <ShopPhoto photo={tile.photo} size="card" alt="" className="aspect-[4/3] w-full" />
                            <span className="truncate p-3 text-label-14 text-ink group-hover:underline">{tile.name}</span>
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
            <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
                <Link href={link('storefront.content.project', { slug: project.slug })} className="group relative block overflow-hidden rounded-xl" data-test="home-project">
                    <ShopPhoto photo={Array.isArray(project.image) ? null : project.image} size="detail" alt="" className="aspect-[2/1] w-full" />
                    {/* The design's caption: the sidebar's navy, dark in both modes, solid behind the words and fading out above them; the words light (shop-design.md §12, bug 1). */}
                    <span className="absolute inset-x-0 bottom-0 grid gap-1 bg-linear-to-t from-sidebar/85 from-60% to-transparent p-4 pt-12 text-sidebar-ink" data-test="home-project-caption">
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

    // The design's two blocks (H4): small headings, one button each; the companies' block warm, its button copper.
    return (
        <section className="shop-box grid gap-4 sm:grid-cols-2" data-test="home-audience">
            {blocks.map((block, index) =>
                block?.title ? (
                    <div key={index} className={`grid content-start gap-3 rounded-xl border p-6 ${index === 1 ? 'border-line-warm bg-sand text-sand-ink' : 'border-line bg-surface'}`} data-test={index === 1 ? 'audience-companies' : 'audience-individuals'}>
                        <h3 className={`text-heading-16 ${index === 1 ? 'text-sand-ink' : 'text-ink'}`}>{block.title}</h3>
                        {block.line ? <p className={`text-copy-14 ${index === 1 ? 'text-sand-ink' : 'text-ink-muted'}`}>{block.line}</p> : null}
                        {block.button?.text && block.button.link ? (
                            <Button asChild className={`h-[46px] justify-self-start ${index === 1 ? 'bg-accent text-ink-on-brand hover:bg-accent/90' : ''}`}>
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

    // The design's band (H5): a warm ground, a 30 px heading, and two cards whose figures are the store's settings, never typed (Q16 (b)).
    return (
        <section className={`bg-sand text-sand-ink ${theme.pointsArt ? 'bg-cover bg-center [background-image:var(--tw-theme-points)]' : ''}`} data-test="home-points">
            <div className="shop-box grid gap-6 py-8 sm:grid-cols-[3fr_2fr] sm:items-center sm:py-12">
                <div className="grid gap-2">
                    {text(words.small_heading) ? <span className="text-label-13 text-accent">{text(words.small_heading)}</span> : null}
                    {heading ? <h2 className="text-heading-24 sm:text-[30px] sm:leading-tight sm:font-semibold">{heading}</h2> : null}
                    {text(words.line) ? <p className="text-copy-14">{text(words.line)}</p> : null}
                </div>
                <ul className="grid grid-cols-2 gap-3">
                    <li className="grid gap-1 rounded-xl border border-line-warm bg-surface p-4 text-ink" data-test="points-earn">
                        <span className="tw-figure text-heading-24 text-accent">{figure(locale, points.earn)}</span>
                        <span className="text-copy-13 text-ink-muted">{t('content::shop.home.points_earn', { points: figure(locale, points.earn), currency: points.currency })}</span>
                    </li>
                    <li className="grid gap-1 rounded-xl border border-line-warm bg-surface p-4 text-ink" data-test="points-off">
                        <span className="tw-figure text-heading-24 text-accent">{figure(locale, points.off)}</span>
                        <span className="text-copy-13 text-ink-muted">{t('content::shop.home.points_off', { points: figure(locale, points.off), currency: points.currency })}</span>
                    </li>
                </ul>
            </div>
        </section>
    );
}

const TILE = 'grid h-[78px] w-[180px] place-items-center rounded-lg border border-line bg-surface grayscale transition hover:grayscale-0';

function Logos({ heading, logos, art, store, locale }: { heading: string | null; logos: Logo[]; art: boolean; store: string; locale: string }) {
    // The design's band (H7): edge to edge, the logos as tiles, grey until hovered, moving slowly - twice over, so the
    // row joins itself - and still for a person who asked for less motion; the second copy is for the eye only.
    const tile = (logo: Logo, index: number, copy: boolean) => (
        <li key={`${copy ? 'copy' : 'logo'}${index}`} className="shrink-0" aria-hidden={copy || undefined}>
            {/* The copy is a picture only, so the keyboard meets each logo once. */}
            {copy ? (
                <span className={TILE}>
                    <ShopPhoto photo={logo.image} size="card" alt="" className="h-[62px] w-[164px] object-contain" />
                </span>
            ) : (
                <ContentLink link={logo.link} store={store} locale={locale} className={TILE}>
                    <ShopPhoto photo={logo.image} size="card" alt={logo.name ?? ''} className="h-[62px] w-[164px] object-contain" />
                </ContentLink>
            )}
        </li>
    );

    return (
        <section className={`grid gap-4 overflow-hidden border-y border-line bg-surface-sunken py-8 ${art ? 'bg-cover bg-center [background-image:var(--tw-theme-logos)]' : ''}`} data-test="home-logos">
            {heading ? <h2 className="shop-box text-heading-20 text-ink">{heading}</h2> : null}
            {/* One copy is the logos repeated until it is wider than a wide screen (196 px a tile), then drawn twice. */}
            <ul className="flex w-max animate-[tw-marquee_40s_linear_infinite] gap-4 hover:[animation-play-state:paused] motion-reduce:animate-none rtl:animate-[tw-marquee-rtl_40s_linear_infinite] rtl:motion-reduce:animate-none">
                {logos.map((logo, index) => tile(logo, index, false))}
                {Array.from({ length: Math.ceil(2560 / (logos.length * 196)) - 1 }, (_, round) => logos.map((logo, index) => tile(logo, index + (round + 1) * 1000, true)))}
                {Array.from({ length: Math.ceil(2560 / (logos.length * 196)) }, (_, round) => logos.map((logo, index) => tile(logo, index + (round + 1) * 100000, true)))}
            </ul>
        </section>
    );
}
