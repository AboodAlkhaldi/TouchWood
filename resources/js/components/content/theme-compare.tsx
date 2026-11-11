import type { CSSProperties, ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import { isFilled, type PartSpec, type PartState } from './theme-parts';

/*
| The default-and-theme comparison (content.md §1.8 "Legibility", W5b): the part being edited drawn small, beside the form
| on a computer and under it on a phone - the shop as it is, then with the theme - at phone and computer width. Words
| sit on artwork with fixed text colours, which no check can prove readable, so the page shows them together and says
| so. Drawn in the page's language: artwork with words is chosen by it.
|
| Only the picture's address is set inline (a background image, never a colour): every colour is a token (ThemeTokensTest).
*/

type Width = 'phone' | 'desktop';

function art(part: PartState, slot: string, locale: string): string | null {
    const image = part.images[`${slot}:${locale}`] ?? part.images[`${slot}:any`] ?? null;

    return image?.thumbnail ?? null;
}

function ground(url: string | null): CSSProperties | undefined {
    return url === null ? undefined : { backgroundImage: `url("${url}")`, backgroundSize: 'cover', backgroundPosition: 'center' };
}

function Frame({ title, children, test }: { title: string; children: ReactNode; test: string }) {
    return (
        <figure className="grid min-w-0 gap-1" data-test={test}>
            <figcaption className="text-label-12 text-ink-muted">{title}</figcaption>
            <div className="material-small min-w-0 overflow-hidden bg-surface">{children}</div>
        </figure>
    );
}

/** One width's drawing of the part, with or without the theme. */
function Drawing({ spec, part, width, themed }: { spec: PartSpec; part: PartState; width: Width; themed: boolean }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const words = locale === 'ar' ? part.textAr : part.textEn || part.textAr;
    const slot = width === 'phone' ? 'PHONE' : 'DESKTOP';
    const line = 'h-2 rounded-full bg-surface-sunken';

    switch (spec.kind) {
        case 'ANNOUNCEMENT':
            return (
                <div className="bg-surface-sunken" style={themed ? ground(art(part, slot, locale)) : undefined}>
                    <p className="truncate px-3 py-1 text-center text-copy-13 text-ink">{themed && words !== '' ? words : '—'}</p>
                </div>
            );
        case 'HERO':
        case 'LISTING': {
            const url = themed ? art(part, slot, locale) : null;

            return (
                <div className="grid gap-2 p-2">
                    <div className={spec.kind === 'HERO' ? (width === 'phone' ? 'aspect-[2/1] rounded-md bg-surface-sunken' : 'aspect-[16/7] rounded-md bg-surface-sunken') : 'aspect-[16/3] rounded-md bg-surface-sunken'} style={ground(url)} />
                    <div className={line} />
                </div>
            );
        }
        case 'PROMISES':
        case 'POINTS':
        case 'LOGOS':
        case 'FOOTER': {
            const url = themed ? art(part, 'BAND', 'any') : null;
            const promises = part.promises.map((each) => (locale === 'ar' ? each.ar : each.en || each.ar)).filter((each) => each !== '');

            return (
                <div className="grid min-h-16 content-center gap-2 bg-surface-sunken p-3" style={ground(url)}>
                    {themed && words !== '' ? <p className="truncate text-label-13 text-ink">{words}</p> : <div className="h-2 w-1/3 rounded-full bg-surface" />}
                    {spec.kind === 'PROMISES' ? (
                        <div className={width === 'phone' ? 'grid grid-cols-2 gap-1' : 'grid grid-cols-4 gap-1'}>
                            {(themed && promises.length > 0 ? promises : ['', '', '', '']).map((each, index) => (
                                <span key={index} className="truncate rounded-sm bg-surface px-1 text-copy-13 text-ink">
                                    {each || '—'}
                                </span>
                            ))}
                        </div>
                    ) : null}
                </div>
            );
        }
        case 'FLAG':
        case 'BADGE':
            return (
                <div className={width === 'phone' ? 'grid grid-cols-2 gap-2 p-2' : 'grid grid-cols-4 gap-2 p-2'}>
                    {[0, 1].map((index) => (
                        <div key={index} className="relative grid gap-1 rounded-md bg-surface-sunken p-1">
                            {themed && spec.kind === 'FLAG' && art(part, 'RIBBON', 'any') !== null ? <div className="h-3 rounded-sm" style={ground(art(part, 'RIBBON', 'any'))} /> : null}
                            <div className="aspect-square rounded-sm bg-surface" />
                            {themed && spec.kind === 'FLAG' && art(part, 'FLAG', 'any') !== null ? <div className="absolute end-1 top-1 size-5" style={ground(art(part, 'FLAG', 'any'))} /> : null}
                            {themed && spec.kind === 'BADGE' && words !== '' ? (
                                <Badge variant="secondary" className="max-w-full truncate">
                                    {words}
                                </Badge>
                            ) : null}
                            <div className={line} />
                        </div>
                    ))}
                </div>
            );
        case 'CART':
            return (
                <div className="grid gap-2 p-2">
                    {themed && words !== '' ? <p className="truncate rounded-sm bg-surface-sunken px-2 py-1 text-copy-13 text-ink">{words}</p> : null}
                    <div className={line} />
                    <div className={line} />
                </div>
            );
        case 'BACKDROP':
            // Hidden at 1024 and under (§1.8 part 11): a phone never shows it.
            return width === 'phone' ? (
                <div className="grid gap-2 p-2">
                    <div className={line} />
                    <div className={line} />
                </div>
            ) : (
                <div className="flex min-h-24">
                    <div className="w-1/6" style={themed ? ground(art(part, 'START', 'any')) : undefined} />
                    <div className="grid flex-1 content-start gap-2 bg-surface p-2">
                        <div className={line} />
                        <div className={line} />
                    </div>
                    <div className="w-1/6" style={themed ? ground(art(part, 'END', 'any')) : undefined} />
                </div>
            );
        default:
            return <p className="p-2 text-copy-13 text-ink-muted">{t('content::admin_themes.compare.empty')}</p>;
    }
}

export function ThemeCompare({ spec, part }: { spec: PartSpec; part: PartState }) {
    const t = useTranslator();

    return (
        <section className="grid min-w-0 content-start gap-3" aria-labelledby="theme-compare" data-test="theme-compare">
            <h2 id="theme-compare" className="text-heading-16 text-ink">
                {t('content::admin_themes.compare.title')}
            </h2>
            <p className="text-copy-13 text-ink-muted">{t('content::admin_themes.compare.body')}</p>
            <p className="text-label-13 text-ink" data-test="compare-part">
                {t(`content::admin_themes.parts.name.${spec.kind}`)}
            </p>
            {isFilled(part) ? null : <p className="text-copy-13 text-ink-muted">{t('content::admin_themes.compare.empty')}</p>}
            <Tabs defaultValue="phone">
                <TabsList variant="line">
                    <TabsTrigger value="phone" data-test="compare-phone">
                        {t('content::admin_themes.compare.phone')}
                    </TabsTrigger>
                    <TabsTrigger value="desktop" data-test="compare-desktop">
                        {t('content::admin_themes.compare.desktop')}
                    </TabsTrigger>
                </TabsList>
                {(['phone', 'desktop'] as const).map((width) => (
                    <TabsContent key={width} value={width} className="pt-3">
                        <div className={width === 'phone' ? 'mx-auto grid w-48 gap-3' : 'grid gap-3'} aria-hidden>
                            <Frame title={t('content::admin_themes.compare.default')} test={`compare-${width}-default`}>
                                <Drawing spec={spec} part={part} width={width} themed={false} />
                            </Frame>
                            <Frame title={t('content::admin_themes.compare.theme')} test={`compare-${width}-theme`}>
                                <Drawing spec={spec} part={part} width={width} themed />
                            </Frame>
                        </div>
                    </TabsContent>
                ))}
            </Tabs>
        </section>
    );
}
