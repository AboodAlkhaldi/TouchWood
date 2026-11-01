import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Minus, Plus, X } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { SearchField } from '@/components/SearchField';
import { carrierLabel, W } from '@/components/sales/admin';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { figure, toLatinDigits } from '@/lib/digits';
import { Input } from '@/components/ui/input';
import { useLiveRead } from '@/lib/live-read';
import { useTranslator } from '@/lib/t';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { EditPage, EditPreviewData, EditSizeData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| P4 - editing an order (sales.md §4.1), its own page (R1-49): the lines with their pieces and Remove; Add
| Product - a search by name or code in the order's store, each size with its values, today's price and the
| stock available (R2-35); another of the customer's addresses in the store; the gift - keep, swap to a level
| the edit reaches, or remove (§1.7); a reason. Preview works it out - every total, and the money in plain words
| (R2-37); Confirm stays out of reach until the preview is of what is on screen (R2-36). A line ticked "reduced in
| the provider" stays as it is (§1.7).
*/

type Line = { variantId: string; mode: string; name: string; values: string; code: string; quantity: number; unit: MoneyData | null; ticked: boolean };

export default function Edit(page: EditPage) {
    const t = useTranslator();
    const base = `/admin/orders/${page.id}`;
    const [lines, setLines] = useState<Line[]>(page.lines);
    const [address, setAddress] = useState('');
    const [gift, setGift] = useState('KEEP');
    const [reason, setReason] = useState('');
    // At an address at another place (gap 6.1): the carrier asked for (none: the server's pick, shown by the preview) and the fee.
    const [carrier, setCarrier] = useState('');
    const [fee, setFee] = useState<'TODAY' | 'KEEP'>('TODAY');
    const [preview, setPreview] = useState<{ data: EditPreviewData; signature: string; address: string } | null>(null);
    const [refused, setRefused] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [saving, setSaving] = useState(false);
    const sent = lines.filter((line) => line.quantity > 0).map(({ variantId, mode, quantity }) => ({ variantId, mode, quantity }));
    const signature = JSON.stringify({ sent, address, gift, carrier, fee });
    const chooseAddress = (next: string) => {
        setAddress(next);
        setCarrier('');
        setFee('TODAY');
    };
    const current = preview !== null && preview.signature === signature;

    const set = (index: number, quantity: number) => setLines((now) => now.map((line, at) => (at === index ? { ...line, quantity: Math.max(0, quantity) } : line)));
    const add = (size: EditSizeData, mode: 'RETAIL' | 'WHOLESALE') =>
        setLines((now) =>
            now.some((line) => line.variantId === size.variantId && line.mode === mode)
                ? now
                : [...now, { variantId: size.variantId, mode, name: size.name, values: size.values, code: size.code, quantity: mode === 'WHOLESALE' ? (size.wholesaleMinimum ?? 1) : 1, unit: mode === 'WHOLESALE' ? size.wholesale : size.retail, ticked: false }],
        );

    const runPreview = async () => {
        const query = new URLSearchParams();
        sent.forEach((line, index) => {
            query.set(`lines[${index}][variantId]`, line.variantId);
            query.set(`lines[${index}][mode]`, line.mode);
            query.set(`lines[${index}][quantity]`, String(line.quantity));
        });
        if (address !== '') query.set('address', address);
        // Never without a new place: the server refuses a choice with none (§9.6 #3).
        if (address !== '' && carrier !== '') query.set('carrier_id', carrier);
        if (address !== '' && fee === 'KEEP') query.set('shipping_fee', fee);
        query.set('gift', gift);
        setPreviewing(true);
        setRefused(null);

        try {
            const response = await fetch(`${base}/edit/preview?${query.toString()}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const body = (await response.json()) as EditPreviewData | { refused: string };

            if ('refused' in body) {
                setPreview(null);
                setRefused(body.refused);
            } else {
                setPreview({ data: body, signature, address });
            }
        } catch {
            setPreview(null);
            setRefused(t(`${W}.edit.failed`));
        } finally {
            setPreviewing(false);
        }
    };

    // The choices belong to the address previewed: another address has its own, or none at the same place (§9.6 #3).
    const newPlace = preview?.data.placeChanged === true && preview.address === address;
    // What was previewed is what is confirmed: the carrier shown for today's fee; kept, the one picked, none: the order's own.
    const shippingChoice = newPlace ? { shipping_fee: fee, carrier_id: fee === 'KEEP' ? carrier : (preview.data.carrierId ?? '') } : {};
    const confirmReason = !current ? (preview === null ? t(`${W}.edit.preview_first`) : t(`${W}.edit.stale`)) : reason.trim() === '' ? t(`${W}.edit.reason_first`) : undefined;

    return (
        <AdminLayout title={t(`${W}.edit.title`, { number: page.number })} subtitle={t(`${W}.edit.subtitle`)}>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="grid min-w-0 content-start gap-4">
                    <FormError />
                    <Card className="material-base gap-3 border-0 py-4" data-test="edit-lines">
                        <CardHeader className="px-4">
                            <CardTitle>
                                <h2 className="text-heading-16 text-ink">{t(`${W}.edit.lines`)}</h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 px-4">
                            <ul className="grid gap-3">
                                {lines.map((line, index) => (
                                    <li key={`${line.variantId}-${line.mode}`} className={`flex flex-wrap items-center justify-between gap-3 ${line.quantity === 0 ? 'opacity-50' : ''}`} data-test={`edit-line-${line.code}`}>
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="text-label-14 text-ink">{line.name}</span>
                                            {line.values === '' ? null : <span className="text-copy-13 text-ink-muted">{line.values}</span>}
                                            <span className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                                                <Badge variant="outline">{t(`${W}.mode.${line.mode}`)}</Badge>
                                                <bdi dir="ltr" className="tw-figure">
                                                    {line.code}
                                                </bdi>
                                                {line.unit === null ? null : <Money value={line.unit} />}
                                            </span>
                                            {line.ticked ? <span className="text-copy-13 text-ink-muted">{t(`${W}.edit.ticked`)}</span> : null}
                                        </span>
                                        {line.ticked ? null : (
                                            <span className="flex items-center gap-1" role="group" aria-label={t(`${W}.edit.pieces`, { name: line.name })}>
                                                <Button type="button" variant="outline" size="icon" disabled={line.quantity === 0} onClick={() => set(index, line.quantity - 1)} aria-label={t(`${W}.edit.less`)} data-test="fewer">
                                                    <Minus aria-hidden="true" />
                                                </Button>
                                                <Input
                                                    className="tw-figure w-16 text-center"
                                                    inputMode="numeric"
                                                    dir="ltr"
                                                    aria-label={t(`${W}.edit.pieces`, { name: line.name })}
                                                    value={String(line.quantity)}
                                                    onChange={(event) => set(index, Number(toLatinDigits(event.target.value).replace(/[^0-9]/g, '') || '0'))}
                                                    data-test="quantity"
                                                />
                                                <Button type="button" variant="outline" size="icon" onClick={() => set(index, line.quantity + 1)} aria-label={t(`${W}.edit.more`)} data-test="more">
                                                    <Plus aria-hidden="true" />
                                                </Button>
                                                <Button type="button" variant="ghost" size="icon" onClick={() => set(index, 0)} aria-label={`${t(`${W}.edit.remove`)}: ${line.name}`} data-test="remove-line">
                                                    <X aria-hidden="true" />
                                                </Button>
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <AddProduct url={`${base}/edit/sizes`} onAdd={add} />
                        </CardContent>
                    </Card>

                    <Card className="material-base gap-3 border-0 py-4">
                        <CardContent className="grid gap-4 px-4">
                            <div className="grid gap-1">
                                <SelectField id="edit-address" label={t(`${W}.edit.address`)} value={address} onChange={(event) => chooseAddress(event.target.value)} data-test="edit-address">
                                    <NativeSelectOption value="">{t(`${W}.edit.keep_address`)}</NativeSelectOption>
                                    {page.addresses
                                        .filter((each) => each.isComplete)
                                        .map((each) => (
                                            <NativeSelectOption key={each.id} value={each.id}>
                                                {`${each.label} · ${each.recipientName}`}
                                            </NativeSelectOption>
                                        ))}
                                </SelectField>
                                {address === '' ? <p className="text-copy-13 whitespace-pre-line text-ink-muted">{page.address}</p> : null}
                            </div>

                            <div className="grid gap-1">
                                <SelectField id="edit-gift" label={t(`${W}.edit.gift`)} value={gift} onChange={(event) => setGift(event.target.value)} data-test="edit-gift">
                                    <NativeSelectOption value="KEEP">{page.gift === null ? t(`${W}.edit.no_gift`) : `${t(`${W}.edit.gift_keep`)} · ${page.gift}`}</NativeSelectOption>
                                    {page.gift === null ? null : <NativeSelectOption value="REMOVE">{t(`${W}.edit.gift_remove`)}</NativeSelectOption>}
                                    {(preview?.data.gifts ?? []).map((option) => (
                                        <NativeSelectOption key={option.levelId} value={option.levelId}>
                                            {t(`${W}.edit.gift_level`, { name: option.name, amount: option.amount.text })}
                                        </NativeSelectOption>
                                    ))}
                                </SelectField>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="edit-reason">{t(`${W}.edit.reason`)}</Label>
                                <Textarea id="edit-reason" value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} data-test="edit-reason" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card className="material-base h-fit gap-3 border-0 py-4 lg:sticky lg:top-4" data-test="edit-preview">
                    <CardContent className="grid gap-3 px-4">
                        <ActionButton variant="outline" loading={previewing} onClick={runPreview} data-test="preview-edit">
                            {t(`${W}.edit.preview`)}
                        </ActionButton>
                        {refused === null ? null : (
                            <p className="text-copy-14 text-bad" role="alert" data-test="preview-refused">
                                {refused}
                            </p>
                        )}
                        {preview === null ? null : <Preview page={page} preview={preview.data} stale={!current} />}
                        {newPlace ? (
                            <NewPlace
                                page={page}
                                preview={preview.data}
                                fee={fee}
                                carrier={carrier}
                                onFee={(next) => {
                                    setFee(next);
                                    setCarrier('');
                                }}
                                onCarrier={setCarrier}
                            />
                        ) : null}
                        <ActionButton
                            loading={saving}
                            disabledReason={confirmReason}
                            onClick={() =>
                                router.post(`${base}/edit`, { lines: sent, address, gift, reason, ...shippingChoice } as never, { onStart: () => setSaving(true), onFinish: () => setSaving(false) })
                            }
                            data-test="confirm-edit"
                        >
                            {t(`${W}.edit.confirm`)}
                        </ActionButton>
                        <Link href={base} className="text-center text-copy-13 text-ink-muted underline underline-offset-4">
                            {t(`${W}.edit.cancel`)}
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}

function Preview({ page, preview, stale }: { page: EditPage; preview: EditPreviewData; stale: boolean }) {
    const t = useTranslator();
    // [label, before, after, taken off]: before → after for every total (P4); a discount line with nothing in it is not shown.
    const rows: [string, MoneyData | null, MoneyData | null, boolean][] = [
        [t(`${W}.edit.subtotal`), page.beforeSubtotal, preview.subtotal, false],
        [t(`${W}.edit.coupon`), null, preview.coupon, true],
        [t(`${W}.edit.points`), null, preview.points, true],
    ];
    const toBeSet = t(`${W}.edit.shipping_pending`);

    return (
        <div className={`grid gap-2 ${stale ? 'opacity-60' : ''}`} data-test="preview-result">
            <dl className="grid gap-1.5 text-copy-14">
                <div className="flex justify-end gap-3 text-copy-12 text-ink-muted" aria-hidden="true">
                    <span>{t(`${W}.edit.before`)} → {t(`${W}.edit.after`)}</span>
                </div>
                {rows
                    .filter(([, , after]) => after !== null)
                    .map(([label, before, after, minus]) => (
                        <div key={label} className="flex items-baseline justify-between gap-3">
                            <dt className="text-ink-muted">{label}</dt>
                            <dd className="text-end text-ink">
                                {before === null ? null : (
                                    <span className="text-copy-13 text-ink-muted">
                                        <Money value={before} /> →{' '}
                                    </span>
                                )}
                                {minus ? <span aria-hidden="true">− </span> : null}
                                <Money value={after as MoneyData} />
                            </dd>
                        </div>
                    ))}
                <div className="flex items-baseline justify-between gap-3" data-test="preview-shipping">
                    <dt className="text-ink-muted">{t(`${W}.edit.shipping`)}</dt>
                    <dd className="text-end text-ink">
                        <span className="text-copy-13 text-ink-muted">
                            {page.shippingFeePending ? toBeSet : <Money value={page.beforeShipping} />} →{' '}
                        </span>
                        {preview.shippingPending ? toBeSet : <Money value={preview.shipping} />}
                    </dd>
                </div>
                <div className="flex items-baseline justify-between gap-3">
                    <dt className="text-ink-muted">{t(`${W}.edit.vat`)}</dt>
                    <dd className="text-end text-ink">
                        <span className="text-copy-13 text-ink-muted">
                            <Money value={page.beforeVat} /> →{' '}
                        </span>
                        <Money value={preview.vat} />
                    </dd>
                </div>
                <div className="flex items-baseline justify-between gap-3 border-t border-line pt-2">
                    <dt className="text-label-14 text-ink">{preview.shippingPending ? t(`${W}.edit.total_before`) : t(`${W}.edit.total`)}</dt>
                    <dd className="text-end">
                        <s className="me-2 text-copy-13 text-ink-muted">
                            <Money value={preview.before} />
                        </s>
                        <Money value={preview.total} className="text-label-14 text-ink" />
                    </dd>
                </div>
            </dl>
            <p className="text-label-14 text-ink" data-test="preview-money">
                {t(`${W}.edit.${preview.money}`, { amount: preview.difference.text })}
            </p>
            {stale ? <p className="text-copy-13 text-ink-muted">{t(`${W}.edit.stale`)}</p> : null}
        </div>
    );
}

/**
 * An address at another place (§1.7 step 5; gap 6.1): today's fee from the carriers serving it, or the order's own with
 * any of the store's carriers - none to keep while the fee is to be set (§9.6 #2). Changing either makes the preview stale.
 */
function NewPlace({ page, preview, fee, carrier, onFee, onCarrier }: { page: EditPage; preview: EditPreviewData; fee: 'TODAY' | 'KEEP'; carrier: string; onFee: (fee: 'TODAY' | 'KEEP') => void; onCarrier: (carrier: string) => void }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const listed = page.carriers.some((each) => each.id === page.orderedCarrierId);
    const today = (
        <>
            {preview.carriers.length === 0 ? (
                <div className="grid gap-1 text-copy-13 text-ink" data-test="no-carrier">
                    <p>{t(`${W}.edit.no_carrier`)}</p>
                    {page.wayToPay === 'BANK_TRANSFER' ? <p>{t(`${W}.edit.stays_transfer`)}</p> : null}
                </div>
            ) : (
                <RadioGroup value={carrier || (preview.carrierId ?? '')} onValueChange={onCarrier} className="grid gap-2" aria-label={t(`${W}.edit.new_place`)}>
                    {preview.carriers.map((each) => (
                        <Label key={each.id} htmlFor={`carrier-${each.id}`} className="material-small flex cursor-pointer items-center gap-3 p-2 font-normal" data-test={`carrier-${each.id}`}>
                            <RadioGroupItem id={`carrier-${each.id}`} value={each.id} />
                            <span className="flex min-w-0 flex-1 items-baseline justify-between gap-2 text-copy-14">
                                <bdi className="min-w-0 break-words text-ink">{each.name}</bdi>
                                {each.free || each.fee.minor === 0 ? <span className="shrink-0 text-ink">{t(`${W}.edit.free`)}</span> : <Money value={each.fee} className="shrink-0 text-ink" />}
                            </span>
                        </Label>
                    ))}
                </RadioGroup>
            )}
        </>
    );

    return (
        <div className="grid gap-2 border-t border-line pt-3" data-test="new-place">
            <p className="text-label-14 text-ink">{t(`${W}.edit.new_place`)}</p>
            {page.shippingFeePending ? (
                today
            ) : (
                <>
                    <RadioGroup value={fee} onValueChange={(next) => onFee(next as 'TODAY' | 'KEEP')} className="grid gap-2" aria-label={t(`${W}.edit.fee_choice`)}>
                        {(['TODAY', 'KEEP'] as const).map((each) => (
                            <Label key={each} htmlFor={`fee-${each}`} className="flex cursor-pointer items-center gap-2 font-normal" data-test={`fee-${each}`}>
                                <RadioGroupItem id={`fee-${each}`} value={each} />
                                <span className="text-copy-14 text-ink">{each === 'TODAY' ? t(`${W}.edit.fee_today`) : t(`${W}.edit.fee_keep`)}</span>
                            </Label>
                        ))}
                    </RadioGroup>
                    {fee === 'TODAY' ? (
                        today
                    ) : (
                        <SelectField id="keep-carrier" label={t(`${W}.edit.keep_carrier`)} value={carrier || (listed ? (page.orderedCarrierId ?? '') : '')} onChange={(event) => onCarrier(event.target.value)} data-test="keep-carrier">
                            {/* None yet, or the order's carrier since deleted: nothing picked keeps what the order has. */}
                            {listed ? null : <NativeSelectOption value="">{page.orderedCarrier ?? t(`${W}.edit.keep_later`)}</NativeSelectOption>}
                            {page.carriers.map((each) => (
                                <NativeSelectOption key={each.id} value={each.id}>
                                    {carrierLabel(each, locale, t(`${W}.dialog.carrier_off`))}
                                </NativeSelectOption>
                            ))}
                        </SelectField>
                    )}
                </>
            )}
        </div>
    );
}

/** Add Product (R2-35; §4.2 B7): the order's store's sizes by name or code, as it is typed. */
function AddProduct({ url, onAdd }: { url: string; onAdd: (size: EditSizeData, mode: 'RETAIL' | 'WHOLESALE') => void }) {
    const t = useTranslator();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const found = useLiveRead<EditSizeData[]>(url, open ? { q } : null);

    if (!open) {
        return (
            <Button type="button" variant="outline" className="w-fit" onClick={() => setOpen(true)} data-test="add-product">
                <Plus aria-hidden="true" />
                {t(`${W}.edit.add`)}
            </Button>
        );
    }

    return (
        <div className="grid gap-2 border-t border-line pt-3" data-test="add-product-search">
            <SearchField id="edit-size-search" label={t(`${W}.edit.search`)} placeholder={t(`${W}.edit.search`)} value={q} onValueChange={setQ} data-test="edit-size-search" />
            {found.data !== null && found.data.length === 0 ? <p className="text-copy-13 text-ink-muted">{t(`${W}.edit.none_found`)}</p> : null}
            <ul className="grid gap-2">
                {(found.data ?? []).map((size) => (
                    <li key={size.variantId} className="material-small flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-test={`edit-size-${size.code}`}>
                        <span className="grid min-w-0 gap-0.5">
                            <span className="text-label-14 text-ink">{size.name}</span>
                            {size.values === '' ? null : <span className="text-copy-13 text-ink-muted">{size.values}</span>}
                            <span className="text-copy-13 text-ink-muted">
                                <bdi dir="ltr" className="tw-figure">
                                    {size.code}
                                </bdi>
                                {' · '}
                                {size.available === null ? t(`${W}.edit.not_counted`) : t(`${W}.edit.available`, { count: figure('en', size.available) })}
                            </span>
                        </span>
                        <span className="flex flex-wrap gap-2">
                            {size.retail === null ? null : (
                                <Button type="button" size="sm" variant="outline" onClick={() => onAdd(size, 'RETAIL')} data-test="add-retail">
                                    {t(`${W}.edit.retail`)} · <Money value={size.retail} />
                                </Button>
                            )}
                            {size.wholesale === null ? null : (
                                <Button type="button" size="sm" variant="outline" onClick={() => onAdd(size, 'WHOLESALE')} data-test="add-wholesale">
                                    {t(`${W}.edit.wholesale`)} · <Money value={size.wholesale} /> {t(`${W}.edit.wholesale_from`, { count: figure('en', size.wholesaleMinimum ?? 1) })}
                                </Button>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
