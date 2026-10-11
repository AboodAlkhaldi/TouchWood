import { useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { StoreFileItemData, StoreFilePage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';

/*
| One store file (catalog.md §4.4 S10, §1.3; amendment 6(g)), with no uploader shown: a Note, once -
| the prices and stock are shown and not kept until Pricing and Inventory exist -, then the items:
| number · code · its product (a link to its page) · price · stock · where it stands now (Ready · Not
| Ready and what it lacks · Archived · Already On · Unknown Code), or what was done (Switched On ·
| Removed). Ticked open items: **Switch On Selected**, **Remove Selected…**; and **Switch On Every
| Ready One**. An unknown code: **Correct Code…**, checked again. A product not ready is completed in
| its own page, then switched on here.
*/

const STANDING_TONE: Record<string, Tone> = {
    READY: 'green-subtle',
    NOT_READY: 'amber-subtle',
    ARCHIVED: 'gray-subtle',
    ALREADY_ON: 'blue-subtle',
    UNKNOWN: 'red-subtle',
};

export default function Show({ id, fileName, uploadedAt, storeCode, storeName, pricesKept, items }: StoreFilePage) {
    const t = useTranslator();
    const locale = useLocale();
    const [selected, setSelected] = useState<string[]>([]);
    const [dialog, setDialog] = useState<{ action: 'remove' } | { action: 'code'; itemId: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const open = items.filter((item) => item.state === 'OPEN');
    // Only items still open are chosen: one switched on or removed since leaves the choice.
    const chosen = selected.filter((itemId) => open.some((item) => item.id === itemId));
    const ready = open.some((item) => item.standing === 'READY');
    const correcting = dialog?.action === 'code' ? (open.find((item) => item.id === dialog.itemId) ?? null) : null;
    const noneChosen = chosen.length === 0 ? t('catalog::admin_store_files.none_selected') : undefined;
    // The whole choice is refused when one of it is not ready: said before the press.
    const notReady = chosen.some((itemId) => open.find((item) => item.id === itemId)?.standing !== 'READY') ? t('catalog::admin_store_files.only_ready') : undefined;
    const switchOn = (itemIds: string[] | null) =>
        router.post(`/admin/store-files/${id}/switch-on`, itemIds === null ? {} : { item_ids: itemIds }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setSelected([]),
        });
    const toggle = (itemId: string, on: boolean) => setSelected((before) => (on ? [...before.filter((other) => other !== itemId), itemId] : before.filter((other) => other !== itemId)));
    const every = open.length > 0 && chosen.length === open.length;

    return (
        <AdminLayout title={fileName} subtitle={storeName} breadcrumbs={[{ label: t('catalog::admin_store_files.back'), href: `/admin/store-files?store=${storeCode}` }]}>
            <div className="grid gap-4">
                <FormError />
                <p className="text-copy-14 text-ink-muted">
                    <Time value={uploadedAt} mode="absolute" />
                </p>
                {pricesKept ? null : <Note data-test="prices-note">{t('catalog::admin_store_files.prices_note')}</Note>}

                <div className="flex flex-wrap items-center gap-3">
                    <ActionButton type="button" loading={busy} disabledReason={noneChosen ?? notReady} onClick={() => switchOn(chosen)} data-test="switch-on-selected">
                        {t('catalog::admin_store_files.switch_on_selected')}
                    </ActionButton>
                    <ActionButton type="button" variant="secondary" loading={busy} disabledReason={ready ? undefined : t('catalog::admin_store_files.none_ready')} onClick={() => switchOn(null)} data-test="switch-on-ready">
                        {t('catalog::admin_store_files.switch_on_ready')}
                    </ActionButton>
                    <ActionButton
                        type="button"
                        variant="outline"
                        disabledReason={noneChosen}
                        onClick={(event) => {
                            opener.current = event.currentTarget;
                            setDialog({ action: 'remove' });
                        }}
                        data-test="remove-selected"
                    >
                        {t('catalog::admin_store_files.remove_selected')}
                    </ActionButton>
                </div>

                <div className="material-base overflow-x-auto">
                    <Table>
                        <TableCaption className="sr-only">{fileName}</TableCaption>
                        <TableHeader className="bg-surface-sunken">
                            <TableRow>
                                <TableHead className="w-10">
                                    <Checkbox
                                        checked={every}
                                        disabled={open.length === 0 || busy}
                                        onCheckedChange={(on) => setSelected(on === true ? open.map((item) => item.id) : [])}
                                        aria-label={t('catalog::admin_store_files.select_open')}
                                        data-test="select-open"
                                    />
                                </TableHead>
                                <TableHead className="text-end">{t('catalog::admin_store_files.column.number')}</TableHead>
                                <TableHead>{t('catalog::admin_store_files.column.code')}</TableHead>
                                <TableHead>{t('catalog::admin_store_files.column.product')}</TableHead>
                                <TableHead className="text-end">{t('catalog::admin_store_files.column.price')}</TableHead>
                                <TableHead className="text-end">{t('catalog::admin_store_files.column.stock')}</TableHead>
                                <TableHead>{t('catalog::admin_store_files.column.standing')}</TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) => (
                                <Row
                                    key={item.id}
                                    item={item}
                                    chosen={chosen.includes(item.id)}
                                    busy={busy}
                                    onChoose={(on) => toggle(item.id, on)}
                                    onCorrect={(trigger) => {
                                        opener.current = trigger;
                                        setDialog({ action: 'code', itemId: item.id });
                                    }}
                                    nameOf={(ar, en) => nameIn(locale, ar, en)}
                                    figureOf={(value) => figure(locale, value)}
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {dialog?.action === 'remove' ? <RemoveDialog fileId={id} itemIds={chosen} onDone={() => setSelected([])} onOpenChange={(next) => (next ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
            {correcting !== null ? <CodeDialog fileId={id} item={correcting} onOpenChange={(next) => (next ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function Row({
    item,
    chosen,
    busy,
    onChoose,
    onCorrect,
    nameOf,
    figureOf,
}: {
    item: StoreFileItemData;
    chosen: boolean;
    busy: boolean;
    onChoose: (on: boolean) => void;
    onCorrect: (trigger: HTMLElement) => void;
    nameOf: (ar: string | null, en: string | null) => string;
    figureOf: (value: number) => string;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const isOpen = item.state === 'OPEN';

    return (
        <TableRow data-test={`item-${item.number}`} data-state={item.state}>
            <TableCell>
                {isOpen ? (
                    <Checkbox checked={chosen} disabled={busy} onCheckedChange={(on) => onChoose(on === true)} aria-label={t('catalog::admin_store_files.select_item', { number: String(item.number) })} data-test={`select-${item.number}`} />
                ) : null}
            </TableCell>
            <TableCell className="tw-figure text-end text-ink-muted">{figureOf(item.number)}</TableCell>
            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                {item.code}
            </TableCell>
            <TableCell>
                {item.productId !== null ? (
                    <Link href={`/admin/products/${item.productId}`} className="text-label-14 text-ink underline-offset-4 hover:underline">
                        {nameOf(item.productNameAr, item.productNameEn)}
                    </Link>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell className="tw-figure text-end">
                <bdi dir="ltr">{item.price}</bdi>
            </TableCell>
            <TableCell className="tw-figure text-end">{item.stock === null ? '—' : figureOf(item.stock)}</TableCell>
            <TableCell>
                <div className="grid gap-1">
                    {isOpen && item.standing !== null ? (
                        <Badge className={tone(STANDING_TONE[item.standing] ?? 'gray-subtle')} data-test="standing">
                            {t(`catalog::admin_store_files.standing.${item.standing}`)}
                        </Badge>
                    ) : (
                        <Badge className={tone('gray-subtle')} data-test="standing">
                            {t(`catalog::admin_store_files.state.${item.state}`)}
                        </Badge>
                    )}
                    {item.missing.length > 0 ? (
                        <span className="text-copy-13 text-ink-muted">
                            {t('catalog::admin_store_files.lacks')} {item.missing.map((rule) => t(`catalog::admin_products.missing.${rule}`)).join(locale === 'ar' ? '، ' : ', ')}
                        </span>
                    ) : null}
                    {item.lines.map((line) => (
                        <span key={line} className="text-copy-13 text-ink-muted">
                            {line}
                        </span>
                    ))}
                </div>
            </TableCell>
            <TableCell className="text-end">
                {isOpen && item.standing === 'UNKNOWN' ? (
                    <Button type="button" variant="outline" size="sm" disabled={busy} onClick={(event) => onCorrect(event.currentTarget)} data-test={`correct-${item.number}`}>
                        {t('catalog::admin_store_files.correct')}
                    </Button>
                ) : null}
            </TableCell>
        </TableRow>
    );
}

function RemoveDialog({ fileId, itemIds, onDone, onOpenChange, returnFocusTo }: { fileId: string; itemIds: string[]; onDone: () => void; onOpenChange: (open: boolean) => void; returnFocusTo: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_store_files.remove_title')}
            description={t('catalog::admin_store_files.remove_body')}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(
                            `/admin/store-files/${fileId}/remove`,
                            { item_ids: itemIds },
                            {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onSuccess: () => {
                                    onDone();
                                    onOpenChange(false);
                                },
                            },
                        )
                    }
                    data-test="confirm-remove"
                >
                    {t('catalog::admin_store_files.remove_confirm')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}

function CodeDialog({ fileId, item, onOpenChange, returnFocusTo }: { fileId: string; item: StoreFileItemData; onOpenChange: (open: boolean) => void; returnFocusTo: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const form = useForm({ code: '' });

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_store_files.correct_title', { code: item.code })}
            description={t('catalog::admin_store_files.correct_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} onClick={() => form.post(`/admin/store-files/${fileId}/items/${item.id}/code`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })} data-test="confirm-code">
                    {t('catalog::admin_store_files.correct_confirm')}
                </ActionButton>
            }
        >
            <TextField
                id="item-code"
                dir="ltr"
                inputMode="numeric"
                inputClassName="tw-figure font-mono"
                label={t('catalog::admin_store_files.correct_field')}
                value={form.data.code}
                error={form.errors.code}
                onChange={(event) => form.setData('code', toLatinDigits(event.target.value))}
                data-test="item-code"
            />
        </PanelDialog>
    );
}
