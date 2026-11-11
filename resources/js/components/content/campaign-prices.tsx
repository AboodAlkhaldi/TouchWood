import { lazy, Suspense, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { formatMoment } from '@/components/Time';
import { Note } from '@/components/Note';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { useLiveRead } from '@/lib/live-read';
import { rowErrors } from '@/lib/row-errors';
import { useTranslator } from '@/lib/t';
import type { CampaignPreviewData, CampaignRunData, CampaignTargetData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| One store's prices of a campaign (content.md §1.7 #4-#6, W4b's Prices tab): Retail, Wholesale or Both; what it discounts -
| products, sizes, categories, brands, each a percentage off, a size optionally a fixed price in the store's currency -
| and the products left out of its categories and brands. **Pricing's preview, live**: read 300 ms after a change, its
| last answer dimmed while the next is on its way (`useLiveRead`), with the store's discount ceiling as Pricing's screens
| say it, the sizes it skips and why, and every other offer on them. Read-only for someone without Pricing's right there
| (§1.7 #5), and once the run's start has passed (Pricing judges "started" by the dates, whatever the state says).
|
| Save Prices keeps what was typed when it is refused (the page is not remounted), and the sizes Pricing names are
| marked in the preview and on their rows (`rows.*`, `FormErrors::rows`). The picker loads when asked: never on first
| paint, so React.lazy is safe under SSR (lesson 180).
*/

const TargetPicker = lazy(() => import('./target-picker').then((module) => ({ default: module.TargetPicker })));

const KINDS = ['PRODUCT', 'SIZE', 'CATEGORY', 'BRAND'] as const;
const LINES = ['RETAIL', 'WHOLESALE', 'BOTH'] as const;

type Kind = (typeof KINDS)[number];

/** A target as typed: its percentage or, a size's, its fixed price, as text. */
type Typed = { kind: string; id: string; nameAr: string; nameEn: string | null; detail: string | null; archived: boolean; mode: 'percent' | 'fixed'; value: string };

export type Picked = { id: string; nameAr: string; nameEn: string | null; detail: string | null };

function typedMinor(minor: number, decimals: number): string {
    if (decimals <= 0) {
        return String(minor);
    }

    const digits = String(minor).padStart(decimals + 1, '0');

    return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

/** A percentage as typed, in basis points: 0.01 to 99.99, two places at most; null otherwise. */
export function basisPoints(text: string): number | null {
    const match = /^(\d{1,2})(?:\.(\d{1,2}))?$/.exec(text.trim());

    if (match === null) {
        return null;
    }

    const points = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));

    return points >= 1 && points <= 9999 ? points : null;
}

/** A price as typed, in the currency's minor units: above 0, at most its decimals; null otherwise. */
export function minorUnits(text: string, decimals: number): number | null {
    const match = /^(\d{1,9})(?:\.(\d+))?$/.exec(text.trim());

    if (match === null || (match[2] ?? '').length > decimals) {
        return null;
    }

    const minor = Number(match[1]) * 10 ** decimals + Number((match[2] ?? '').padEnd(decimals, '0') || '0');

    return minor > 0 ? minor : null;
}

function typedOf(target: CampaignTargetData, decimals: number): Typed {
    return {
        kind: target.kind,
        id: target.id,
        nameAr: target.nameAr,
        nameEn: target.nameEn,
        detail: target.detail,
        archived: target.archived,
        mode: target.amountMinor !== null ? 'fixed' : 'percent',
        value: target.amountMinor !== null ? typedMinor(target.amountMinor, decimals) : target.percentBp === null ? '' : String(target.percentBp / 100),
    };
}

export function CampaignPrices({ run, version }: { run: CampaignRunData; version: number }) {
    const t = useTranslator();
    const name = useInLanguage();
    const { errors, locale } = usePage<SharedProps>().props;
    const marked = rowErrors(errors);
    const editable = run.mayPrice && run.pricesOpen;
    const [lines, setLines] = useState(run.prices?.lines ?? 'BOTH');
    const [targets, setTargets] = useState<Typed[]>(() => (run.prices?.targets ?? []).map((target) => typedOf(target, run.decimals)));
    const [leftOut, setLeftOut] = useState<Picked[]>(() => (run.prices?.leftOut ?? []).map((product) => ({ id: product.id, nameAr: product.nameAr, nameEn: product.nameEn, detail: null })));
    const [picking, setPicking] = useState<Kind | 'LEFT_OUT' | null>(null);
    const [busy, setBusy] = useState(false);
    const [tried, setTried] = useState(false);
    const [changed, setChanged] = useState(false);
    const problem = (target: Typed): string | undefined =>
        target.mode === 'fixed'
            ? minorUnits(target.value, run.decimals) === null
                ? t('content::admin_campaigns.prices.amount_rule', { currency: run.currency })
                : undefined
            : basisPoints(target.value) === null
              ? t('content::admin_campaigns.prices.percent_rule')
              : undefined;
    const valid = targets.every((target) => problem(target) === undefined);
    const sent = {
        lines,
        targets: targets.map((target) =>
            target.mode === 'fixed' ? { kind: target.kind, id: target.id, amountMinor: minorUnits(target.value, run.decimals) } : { kind: target.kind, id: target.id, percentBp: basisPoints(target.value) },
        ),
        leftOut: leftOut.map((product) => product.id),
    };
    const preview = useLiveRead<CampaignPreviewData>('/admin/content/campaigns/preview', targets.length > 0 && valid ? { run: run.id, prices: JSON.stringify(sent) } : null);
    const ceiling = run.ceilingPercent;
    const deep = ceiling !== null && preview.data?.deepestBasisPoints != null && preview.data.deepestBasisPoints > ceiling * 100;
    const touch = (change: () => void) => {
        change();
        setChanged(true);
    };
    const add = (kind: Kind, picked: Picked) =>
        touch(() => setTargets((was) => (was.some((each) => each.kind === kind && each.id === picked.id) ? was : [...was, { kind, ...picked, archived: false, mode: 'percent', value: '' }])));

    const save = () => {
        setTried(true);

        if (!valid) {
            return;
        }

        router.post(`/admin/content/campaigns/runs/${run.id}/prices`, { version, prices: targets.length === 0 && leftOut.length === 0 ? null : sent }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setChanged(false),
        });
    };

    return (
        <div className="grid min-w-0 gap-4" data-test={`prices-${run.store.code}`}>
            {!run.mayPrice ? (
                <Note size="small" data-test="prices-not-allowed">
                    {t('content::admin_campaigns.prices.not_allowed')}
                </Note>
            ) : !run.pricesOpen ? (
                <Note size="small" data-test="prices-fixed">
                    {t('content::admin_campaigns.prices.fixed_running')}
                </Note>
            ) : null}
            {ceiling === null ? null : (
                <p className="tw-figure text-copy-13 text-ink-muted" data-test="ceiling">
                    {t('content::admin_campaigns.prices.ceiling', { percent: figure(locale, ceiling) })}
                </p>
            )}

            <FieldSet className="gap-2">
                <FieldLegend variant="label" className="text-label-14 text-ink">
                    {t('content::admin_campaigns.prices.lines')}
                </FieldLegend>
                <RadioGroup value={lines} onValueChange={(next) => touch(() => setLines(next))} disabled={!editable} className="flex flex-wrap gap-4" data-test="lines">
                    {LINES.map((line) => (
                        <div key={line} className="flex items-center gap-2">
                            <RadioGroupItem id={`lines-${run.id}-${line}`} value={line} data-test={`line-${line}`} />
                            <Label htmlFor={`lines-${run.id}-${line}`} className="font-normal">
                                {t(`content::admin_campaigns.prices.line.${line}`)}
                            </Label>
                        </div>
                    ))}
                </RadioGroup>
            </FieldSet>

            <div className="grid min-w-0 gap-2">
                <h3 className="text-label-14 text-ink">{t('content::admin_campaigns.prices.targets')}</h3>
                {targets.length === 0 ? <p className="text-copy-13 text-ink-muted">{t('content::admin_campaigns.prices.none')}</p> : null}
                <ul className="grid min-w-0 gap-3" data-test="targets">
                    {targets.map((target, index) => {
                        const id = `target-${run.id}-${index}`;
                        const shown = (tried || target.value !== '' ? problem(target) : undefined) ?? (target.kind === 'SIZE' ? marked[target.id] : undefined);

                        return (
                            <li key={`${target.kind}:${target.id}`} className="material-small grid min-w-0 gap-2 p-3" data-test={`target-${target.kind}-${target.id}`} data-marked={marked[target.id] !== undefined ? 'true' : undefined}>
                                <div className="flex min-w-0 items-start justify-between gap-2">
                                    <div className="grid min-w-0 gap-0.5">
                                        <span className="truncate text-label-14 text-ink">{name(target.nameAr, target.nameEn ?? target.nameAr)}</span>
                                        <span className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                                            {t(`content::admin_campaigns.prices.kind.${target.kind}`)}
                                            {target.detail === null ? null : <bdi dir="ltr">{target.detail}</bdi>}
                                            {target.archived ? <Badge variant="outline">{t('content::admin_campaigns.prices.archived')}</Badge> : null}
                                        </span>
                                    </div>
                                    {editable ? (
                                        <Button type="button" variant="ghost" size="icon" aria-label={`${t('content::admin_campaigns.prices.remove')}: ${name(target.nameAr, target.nameEn ?? target.nameAr)}`} onClick={() => touch(() => setTargets((was) => was.filter((_, at) => at !== index)))} data-test="remove-target">
                                            <X aria-hidden />
                                        </Button>
                                    ) : null}
                                </div>
                                <div className="flex flex-wrap items-end gap-3">
                                    {target.kind === 'SIZE' ? (
                                        <RadioGroup
                                            value={target.mode}
                                            onValueChange={(mode) => touch(() => setTargets((was) => was.map((each, at) => (at === index ? { ...each, mode: mode as Typed['mode'], value: '' } : each))))}
                                            disabled={!editable}
                                            className="flex gap-3"
                                        >
                                            {(['percent', 'fixed'] as const).map((mode) => (
                                                <div key={mode} className="flex items-center gap-2">
                                                    <RadioGroupItem id={`${id}-${mode}`} value={mode} />
                                                    <Label htmlFor={`${id}-${mode}`} className="font-normal">
                                                        {t(`content::admin_campaigns.prices.${mode}`)}
                                                    </Label>
                                                </div>
                                            ))}
                                        </RadioGroup>
                                    ) : null}
                                    <Field className="w-44" data-invalid={shown ? true : undefined}>
                                        <FieldLabel htmlFor={id} className={target.kind === 'SIZE' ? 'sr-only' : undefined}>
                                            {t(`content::admin_campaigns.prices.${target.mode}`)}
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id={id}
                                                dir="ltr"
                                                inputMode="decimal"
                                                className="tw-figure"
                                                disabled={!editable}
                                                value={target.value}
                                                aria-invalid={shown ? true : undefined}
                                                aria-describedby={shown ? `${id}-error` : undefined}
                                                onChange={(event) => {
                                                    const value = toLatinDigits(event.target.value);
                                                    touch(() => setTargets((was) => was.map((each, at) => (at === index ? { ...each, value } : each))));
                                                }}
                                                data-test="target-value"
                                            />
                                            <InputGroupAddon align="inline-end">
                                                <InputGroupText>{target.mode === 'fixed' ? run.currency : '%'}</InputGroupText>
                                            </InputGroupAddon>
                                        </InputGroup>
                                        {shown ? (
                                            <FieldError id={`${id}-error`} data-test="target-error">
                                                {shown}
                                            </FieldError>
                                        ) : null}
                                    </Field>
                                </div>
                            </li>
                        );
                    })}
                </ul>
                {editable ? (
                    <div className="flex flex-wrap gap-2">
                        {KINDS.map((kind) => (
                            <Button key={kind} type="button" variant="outline" size="sm" onClick={() => setPicking(kind)} data-test={`add-${kind}`}>
                                {t('content::admin_campaigns.prices.add', { kind: t(`content::admin_campaigns.prices.kind.${kind}`) })}
                            </Button>
                        ))}
                    </div>
                ) : null}
            </div>

            <div className="grid min-w-0 gap-2">
                <h3 className="text-label-14 text-ink">{t('content::admin_campaigns.prices.left_out')}</h3>
                <p className="text-copy-13 text-ink-muted">{t('content::admin_campaigns.prices.left_out_helper')}</p>
                <ul className="flex flex-wrap gap-2" data-test="left-out">
                    {leftOut.map((product) => (
                        <li key={product.id}>
                            <Badge variant="outline" className="gap-1">
                                <span className="max-w-48 truncate">{name(product.nameAr, product.nameEn ?? product.nameAr)}</span>
                                {editable ? (
                                    <button type="button" aria-label={`${t('content::admin_campaigns.prices.remove')}: ${name(product.nameAr, product.nameEn ?? product.nameAr)}`} onClick={() => touch(() => setLeftOut((was) => was.filter((each) => each.id !== product.id)))}>
                                        <X className="size-3" aria-hidden />
                                    </button>
                                ) : null}
                            </Badge>
                        </li>
                    ))}
                </ul>
                {editable ? (
                    <div>
                        <Button type="button" variant="outline" size="sm" onClick={() => setPicking('LEFT_OUT')} data-test="leave-out">
                            {t('content::admin_campaigns.prices.leave_out')}
                        </Button>
                    </div>
                ) : null}
            </div>

            {editable ? (
                <div className="flex flex-wrap items-center gap-3">
                    <ActionButton type="button" loading={busy} onClick={save} data-test="save-prices">
                        {t('content::admin_campaigns.prices.save')}
                    </ActionButton>
                    {changed ? (
                        <span className="text-copy-13 text-ink-muted" role="status" data-test="prices-unsaved">
                            {t('content::admin_campaigns.prices.unsaved')}
                        </span>
                    ) : null}
                </div>
            ) : null}

            <PricesPreview run={run} preview={preview} deep={deep} marked={marked} empty={targets.length === 0} />

            {run.skipped.length === 0 ? null : (
                <Note variant="warning" size="small" data-test="skipped-now">
                    <span className="grid gap-1">
                        <span>{t('content::admin_campaigns.prices.skipped_now')}</span>
                        <span className="flex flex-wrap gap-2">
                            {run.skipped.map((size) => (
                                <span key={size.id} className="text-copy-13">
                                    {name(size.nameAr, size.nameEn ?? size.nameAr)} <bdi dir="ltr">{size.detail}</bdi>
                                </span>
                            ))}
                        </span>
                    </span>
                </Note>
            )}

            {picking === null ? null : (
                <Suspense fallback={null}>
                    <TargetPicker
                        run={run.id}
                        kind={picking === 'LEFT_OUT' ? 'PRODUCT' : picking}
                        title={picking === 'LEFT_OUT' ? t('content::admin_campaigns.prices.leave_out') : t('content::admin_campaigns.prices.add', { kind: t(`content::admin_campaigns.prices.kind.${picking}`) })}
                        chosen={picking === 'LEFT_OUT' ? leftOut.map((each) => each.id) : targets.filter((each) => each.kind === picking).map((each) => each.id)}
                        onPick={(picked) => (picking === 'LEFT_OUT' ? touch(() => setLeftOut((was) => (was.some((each) => each.id === picked.id) ? was : [...was, picked]))) : add(picking, picked))}
                        onClose={() => setPicking(null)}
                    />
                </Suspense>
            )}
        </div>
    );
}

/**
 * Pricing's preview of what is typed (§1.7 #6): the totals, the deepest reduction against the ceiling, each product's
 * sizes with their new price or why they are skipped, the sizes a refusal names marked, and the other offers.
 */
function PricesPreview({ run, preview, deep, marked, empty }: { run: CampaignRunData; preview: ReturnType<typeof useLiveRead<CampaignPreviewData>>; deep: boolean; marked: Record<string, string>; empty: boolean }) {
    const t = useTranslator();
    const name = useInLanguage();
    const { locale } = usePage<SharedProps>().props;
    const data = preview.data;
    const percent = (points: number) => figure(locale, points / 100);

    return (
        <section className="grid min-w-0 gap-3" aria-labelledby={`preview-${run.id}`} aria-busy={preview.loading} data-test="preview">
            <div className="flex items-center gap-2">
                <h3 id={`preview-${run.id}`} className="text-label-14 text-ink">
                    {t('content::admin_campaigns.preview.title')}
                </h3>
                {preview.loading ? <Spinner aria-label={t('content::admin_campaigns.preview.refreshing')} /> : null}
            </div>
            {empty ? <p className="text-copy-13 text-ink-muted">{t('content::admin_campaigns.preview.empty')}</p> : null}
            {preview.failed ? (
                <Note variant="error" size="small" action={<Button type="button" size="sm" variant="outline" onClick={preview.retry}>{t('content::admin_campaigns.preview.retry')}</Button>}>
                    {t('content::admin_campaigns.preview.failed')}
                </Note>
            ) : null}
            {data === null ? null : (
                <div className={preview.loading ? 'grid min-w-0 gap-3 opacity-60' : 'grid min-w-0 gap-3'}>
                    <p className="tw-figure text-copy-13 text-ink" data-test="preview-totals">
                        {t('content::admin_campaigns.preview.reached', { products: figure(locale, data.reachedProducts), sizes: figure(locale, data.reachedSizes) })}
                        {data.skippedSizes > 0 ? ` · ${t('content::admin_campaigns.preview.skipped', { count: figure(locale, data.skippedSizes) })}` : ''}
                        {data.deepestBasisPoints === null ? '' : ` · ${t('content::admin_campaigns.preview.deepest', { percent: percent(data.deepestBasisPoints) })}`}
                    </p>
                    {deep && run.ceilingPercent !== null ? (
                        <p className="text-copy-13 text-warn" data-test="preview-over-ceiling">
                            {t('content::admin_campaigns.prices.over_ceiling', { ceiling: figure(locale, run.ceilingPercent) })}
                        </p>
                    ) : null}
                    {data.products.map((product) => (
                        <div key={product.productId} className="material-small min-w-0 overflow-x-auto">
                            <p className="truncate px-3 pt-2 text-label-13 text-ink">{name(product.nameAr, product.nameEn ?? product.nameAr)}</p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('content::admin_campaigns.preview.columns.size')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.preview.columns.retail')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.preview.columns.new')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.preview.columns.off')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {product.sizes.map((size) => (
                                        <TableRow key={size.variantId} data-test={`preview-size-${size.variantId}`} data-marked={marked[size.variantId] !== undefined ? 'true' : undefined} className={marked[size.variantId] !== undefined ? 'bg-bad-soft' : undefined}>
                                            <TableCell>
                                                <bdi dir="ltr">{size.code}</bdi>
                                                {marked[size.variantId] !== undefined ? <span className="block text-copy-13 text-bad">{marked[size.variantId]}</span> : null}
                                            </TableCell>
                                            <TableCell className="tw-figure whitespace-nowrap">{size.retail === null ? '—' : <bdi dir="ltr">{`${size.retail} ${data.currency}`}</bdi>}</TableCell>
                                            <TableCell className="tw-figure whitespace-nowrap">
                                                {size.skipped !== null && size.skipped !== 'past_ceiling' ? (
                                                    <span className="text-ink-muted">{t(`content::admin_campaigns.preview.skip.${size.skipped}`)}</span>
                                                ) : size.newPrice === null ? (
                                                    '—'
                                                ) : (
                                                    <bdi dir="ltr">{`${size.newPrice} ${data.currency}`}</bdi>
                                                )}
                                                {size.skipped === 'past_ceiling' ? <span className="block text-copy-13 text-warn">{t('content::admin_campaigns.preview.skip.past_ceiling')}</span> : null}
                                            </TableCell>
                                            <TableCell className="tw-figure whitespace-nowrap">{size.depthBp === null ? '—' : `${percent(size.depthBp)}%`}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    ))}
                    {data.otherOffers.length === 0 ? null : (
                        <div className="grid gap-1" data-test="preview-offers">
                            <h4 className="text-label-13 text-ink">{t('content::admin_campaigns.preview.offers')}</h4>
                            <ul className="grid gap-1">
                                {data.otherOffers.map((offer) => (
                                    <li key={`${offer.kind}:${offer.id}`} className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                                        <span className="text-ink">{t(`content::admin_campaigns.preview.offer.${offer.kind}`)}</span>
                                        <span className="tw-figure">
                                            {formatMoment(offer.startsAt, run.store.timezone, locale)} – {offer.endsAt === null ? '—' : formatMoment(offer.endsAt, run.store.timezone, locale)}
                                        </span>
                                        <span className="tw-figure">{t('content::admin_campaigns.preview.offer_sizes', { count: figure(locale, offer.sizes) })}</span>
                                        {offer.alwaysWins ? <Badge variant="outline">{t('content::admin_campaigns.preview.always_wins')}</Badge> : null}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
