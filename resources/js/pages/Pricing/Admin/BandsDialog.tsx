import { useEffect, useRef, useState, type RefObject } from 'react';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { BandData, SizePricesData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { bandProblems, typedOf, type BandsFacts, type Row } from './checks';
import { LoadFailed, readJson, useLocale, useT } from './parts';

export type { BandsFacts } from './checks';

/*
| A size's wholesale bands (pricing.md §4.3.3 P4) - on Wholesale Prices, a Prices row, the size drawer and a
| product's Stores tab: rows of **From** (whole pieces) and **Price**, **Add Band** under them, at most ten
| (Q12), each row's **Remove Band**. Each row checks itself as typed against the rules the server keeps -
| the first at the wholesale minimum and below the retail price, each next from more pieces and cheaper,
| none past the wholesale maximum - and the server names the rule a refusal broke. Opened on a size whose
| minimum moved (Minimum Changed), the first band's message shows at once. All saved together; with every
| row removed the button reads **Remove Wholesale Prices**.
*/

const MOST = 10;




type Props = {
    store: { code: string; decimals: number; currency: string };
    size: { variantId: string; name: string };
    /** What the dialog needs of the size, when the page has it; read from the server otherwise. */
    facts?: BandsFacts | null;
    adding?: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
};

export function BandsDialog({ store, size, facts: given = null, adding = false, open, onOpenChange, returnFocusTo }: Props) {
    const t = useT();
    const locale = useLocale();
    const [facts, setFacts] = useState<BandsFacts | null>(given);
    // Each row keeps its own key from first to last, so a message stays on its row when one above is removed.
    const [rows, setRows] = useState<(Row & { key: string })[]>([]);
    const keys = useRef(0);
    const keyed = (row: Row) => ({ ...row, key: `b${++keys.current}` });
    const [touched, setTouched] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);

    // The size's bands and terms, read once when the page does not have them.
    useEffect(() => {
        if (facts !== null) {
            return;
        }

        const controller = new AbortController();
        setFailed(false);
        readJson<SizePricesData>('/admin/prices/size', { store: store.code, size: size.variantId }, controller.signal).then((read) => {
            if (controller.signal.aborted) {
                return;
            }

            setFailed(read === null);

            if (read !== null) {
                setFacts({ retail: read.retail?.price ?? null, minimum: read.wholesaleMinimum, maximum: read.wholesaleMaximum, bands: read.bands, minimumChanged: read.minimumChanged });
            }
        });

        return () => controller.abort();
    }, [facts, store.code, size.variantId, attempt]);

    // The rows start as the size's bands - or one row at the wholesale minimum for new ones - and a moved
    // minimum's message shows at once.
    useEffect(() => {
        if (facts === null) {
            return;
        }

        const first = facts.bands.length > 0 ? facts.bands.map((band) => keyed({ from: String(band.fromQuantity), price: typedOf(band.price.minor, store.decimals) })) : [keyed({ from: facts.minimum === null ? '' : String(facts.minimum), price: '' })];
        setRows(first);
        setTouched(facts.minimumChanged && first[0] !== undefined ? new Set([`${first[0].key}-from`]) : new Set());
    }, [facts, store.decimals]);

    const problems = facts === null ? [] : bandProblems(rows, facts, store.decimals, t, locale);
    const wrong = problems.findIndex((row) => row.from !== undefined || row.price !== undefined);
    const removing = rows.length === 0;
    const title = adding ? t('pricing::admin.bands_dialog.add_title') : t('pricing::admin.bands_dialog.title');
    const change = (index: number, field: keyof Row, value: string) => {
        setRows((before) => before.map((row, at) => (at === index ? { ...row, [field]: toLatinDigits(value) } : row)));
        setTouched((before) => new Set(before).add(`${rows[index]?.key ?? ''}-${field}`));
    };

    function save() {
        if (wrong !== -1) {
            setTouched(new Set(rows.flatMap((row) => [`${row.key}-from`, `${row.key}-price`])));
            const field = problems[wrong]?.from !== undefined ? 'from' : 'price';
            document.getElementById(`band-${rows[wrong]?.key ?? ''}-${field}`)?.focus();

            return;
        }

        router.post(
            '/admin/wholesale-prices',
            { store: store.code, size: size.variantId, bands: rows.map((row) => ({ from: row.from.trim(), price: row.price.trim() })) },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('pricing::admin.bands_dialog.body', { size: size.name })}
            busy={busy}
            confirm={
                <ActionButton variant={removing ? 'destructive' : 'default'} loading={busy} disabled={facts === null} onClick={save} data-test="confirm-bands">
                    {removing ? t('pricing::admin.bands_dialog.remove') : t('pricing::admin.bands_dialog.save')}
                </ActionButton>
            }
        >
            {facts === null ? (
                failed ? <LoadFailed what="bands" onRetry={() => setAttempt((count) => count + 1)} /> : <Spinner aria-label={t('ui.loading')} className="text-ink-muted" />
            ) : (
                <div className="grid gap-4" data-test="bands-dialog">
                    <ul className="flex flex-wrap gap-x-6 gap-y-1 text-copy-13 text-ink-muted">
                        {facts.retail !== null ? (
                            <li>
                                {t('pricing::admin.bands_dialog.retail')} <Money value={facts.retail} />
                            </li>
                        ) : null}
                        {facts.minimum !== null ? <li className="tw-figure">{t('pricing::admin.bands_dialog.minimum', { minimum: figure(locale, facts.minimum) })}</li> : null}
                        {facts.maximum !== null ? <li className="tw-figure">{t('pricing::admin.bands_dialog.maximum', { maximum: figure(locale, facts.maximum) })}</li> : null}
                    </ul>
                    {removing ? (
                        <p className="text-copy-14 text-ink-muted" data-test="bands-none">
                            {t('pricing::admin.bands_dialog.none')}
                        </p>
                    ) : (
                        <Table>
                            <TableCaption className="sr-only">{title}</TableCaption>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('pricing::admin.bands_dialog.from')}</TableHead>
                                    <TableHead>{t('pricing::admin.bands_dialog.price')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('pricing::admin.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, index) => {
                                    const band = t('pricing::admin.bands_dialog.band', { number: figure(locale, index + 1) });
                                    const fromError = touched.has(`${row.key}-from`) ? problems[index]?.from : undefined;
                                    const priceError = touched.has(`${row.key}-price`) ? problems[index]?.price : undefined;

                                    return (
                                        <TableRow key={row.key} data-test={`band-${index}`}>
                                            <TableCell className="align-top">
                                                <Input
                                                    id={`band-${row.key}-from`}
                                                    dir="ltr"
                                                    inputMode="numeric"
                                                    autoComplete="off"
                                                    className="tw-figure w-24"
                                                    aria-label={`${band}: ${t('pricing::admin.bands_dialog.from')}`}
                                                    aria-invalid={fromError ? true : undefined}
                                                    aria-describedby={fromError ? `band-${row.key}-from-error` : undefined}
                                                    value={row.from}
                                                    onChange={(event) => change(index, 'from', event.target.value)}
                                                    data-test={`band-${index}-from`}
                                                />
                                                {fromError ? (
                                                    <FieldError id={`band-${row.key}-from-error`} className="mt-1 max-w-56 text-copy-12">
                                                        {fromError}
                                                    </FieldError>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <InputGroup className="w-32">
                                                    <InputGroupInput
                                                        id={`band-${row.key}-price`}
                                                        dir="ltr"
                                                        inputMode="decimal"
                                                        autoComplete="off"
                                                        className="tw-figure"
                                                        aria-label={`${band}: ${t('pricing::admin.bands_dialog.price')}`}
                                                        aria-invalid={priceError ? true : undefined}
                                                        aria-describedby={priceError ? `band-${row.key}-price-error` : undefined}
                                                        value={row.price}
                                                        onChange={(event) => change(index, 'price', event.target.value)}
                                                        data-test={`band-${index}-price`}
                                                    />
                                                    <InputGroupAddon align="inline-end">
                                                        <InputGroupText>{store.currency}</InputGroupText>
                                                    </InputGroupAddon>
                                                </InputGroup>
                                                {priceError ? (
                                                    <FieldError id={`band-${row.key}-price-error`} className="mt-1 max-w-56 text-copy-12">
                                                        {priceError}
                                                    </FieldError>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={t('pricing::admin.bands_dialog.remove_band', { number: figure(locale, index + 1) })}
                                                    title={t('pricing::admin.bands_dialog.remove_band', { number: figure(locale, index + 1) })}
                                                    onClick={() => setRows((before) => before.filter((each) => each.key !== row.key))}
                                                    data-test={`band-${index}-remove`}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}
                    <ActionButton
                        type="button"
                        variant="outline"
                        className="w-fit"
                        disabledReason={rows.length >= MOST ? t('pricing::admin.bands_dialog.most', { most: figure(locale, MOST) }) : undefined}
                        onClick={() => setRows((before) => [...before, keyed({ from: '', price: '' })])}
                        data-test="add-band"
                    >
                        {t('pricing::admin.bands_dialog.add_band')}
                    </ActionButton>
                </div>
            )}
        </PanelDialog>
    );
}
