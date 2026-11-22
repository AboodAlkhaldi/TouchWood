import { Link, usePage } from '@inertiajs/react';
import { shopHref } from '@/components/content/ContentLink';
import type { Block } from '@/components/content/marks';
import { Note } from '@/components/Note';
import { ContentBlocks } from '@/components/shop/ContentBlocks';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { ShopPolicyPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| A policy's page (flags-2026-11.md S3.1, S3.2): one reading column as a page's (Document.tsx) - the title, "Last updated
| <date>" under it (F15, the date in the page's language, Latin digits, the store's time zone), then the body from its
| blocks. A text no longer valid in this store - a past version, or a policy switched off here (the owner's decisions of
| 2026-11-20) - says so above its body instead: old and no longer valid, the dates it was in force here, and a link to the
| store's current policy of its kind when it has one, named by its kind (an Other by its title: "the current Other" says
| nothing).
*/

export default function Policy(props: ShopPolicyPageData) {
    const t = useTranslator();
    const { shop, locale } = usePage<SharedProps>().props;
    const store = shop?.code ?? '';
    const images = props.images as Record<number, { src: string | null; width: number | null; height: number | null }>;
    const day = (at: string) => new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'long', timeZone: shop?.timezone }).format(new Date(at));
    const date = props.updatedAt === null ? null : day(props.updatedAt);

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <article className="mx-auto grid w-full max-w-3xl min-w-0 gap-6" data-test="content-policy">
                <header className="grid gap-2">
                    <h1 className="text-heading-32 wrap-anywhere text-ink">{props.title}</h1>
                    {date && !props.past ? (
                        <p className="text-copy-14 text-ink-muted" data-test="policy-updated">
                            {t('content::shop.policy.updated', { date })}
                        </p>
                    ) : null}
                </header>
                {props.past ? (
                    <Note size="small" variant="warning" data-test="policy-past">
                        <span className="grid gap-1">
                            <span className="font-medium" data-test="policy-old">
                                {t('content::shop.policy.old', { title: props.title })}
                            </span>
                            {props.inForce.map((period) => (
                                <span key={period.from} data-test="policy-in-force">
                                    {t('content::shop.policy.in_force', { from: day(period.from), to: day(period.until) })}
                                </span>
                            ))}
                            {props.current === null ? null : (
                                <Link href={props.current} className="font-medium underline underline-offset-4" data-test="policy-current">
                                    {t('content::shop.policy.current', { kind: props.kind === 'OTHER' ? props.title : t(`content::policies.kinds.${props.kind.toLowerCase()}`) })}
                                </Link>
                            )}
                        </span>
                    </Note>
                ) : null}
                <ContentBlocks blocks={props.blocks as unknown as Block[]} image={(ref) => images[ref] ?? null} href={(to) => shopHref(to, store, locale)?.href ?? null} />
            </article>
        </StorefrontLayout>
    );
}
