import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchCombobox, type SearchOption } from '@/components/SearchCombobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ImportNameData, ImportPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale, type Locale } from '../parts';
import { isDeciding, SelectAll, useSelection } from './parts';
import { ImportShell } from './shell';

/*
| An import's names (catalog.md §4.4 S11 part 1, §1.12): each name the file uses that the catalog
| lacks, once, grouped by kind - category path, brand, attribute, value, warranty -, with how many
| products use it and, when several catalog items answer to it, how many (amendment 8(d)). Per row, or
| for the ticked rows: **It Means…** (a catalog item of the same kind, a combobox), **Create It…** (a
| value or a category only: its names corrected and completed, a category's own web address when its
| own is taken, amendment 8(c)) or **Refuse It** (the products using it are left out).
|
| Ticked rows take It Means… together when they are of one kind - and, for values, of one attribute -,
| as several spellings of one brand; Create It… is a row's own, its names being its own.
*/

const KINDS = ['CATEGORY', 'BRAND', 'ATTRIBUTE', 'VALUE', 'WARRANTY'] as const;
const CREATED = ['CATEGORY', 'VALUE'];

type Dialog = { action: 'means' | 'refuse'; ids: string[] } | { action: 'create'; ids: [string] } | null;

/** The catalog items a name of this kind may mean: the active ones, as the decision accepts them. */
function choices(page: ImportPage, name: ImportNameData, locale: Locale, t: (key: string) => string): SearchOption[] {
    switch (name.kind) {
        case 'BRAND':
            return (page.brands ?? []).filter((brand) => brand.active).map((brand) => ({ value: brand.id, label: nameIn(locale, brand.nameAr, brand.nameEn) }));
        case 'CATEGORY': {
            // Any active category - a level of the file's path may mean one with sub-categories; a
            // product's own must hold products, which the decision checks and those are marked.
            const lowest = new Set((page.categories ?? []).map((category) => category.id));

            return (page.meansCategories ?? []).map((category) => ({
                value: category.id,
                label: locale === 'ar' ? category.pathAr : category.pathEn,
                description: lowest.has(category.id) ? t('catalog::admin_imports.names.holds_products') : undefined,
            }));
        }
        case 'WARRANTY':
            return (page.warranties ?? []).filter((warranty) => warranty.active).map((warranty) => ({ value: warranty.id, label: nameIn(locale, warranty.nameAr, warranty.nameEn) }));
        case 'ATTRIBUTE':
            return (page.attributes ?? [])
                .filter((attribute) => attribute.active && (name.attributeKind === null || attribute.kind === name.attributeKind))
                .map((attribute) => ({ value: attribute.id, label: nameIn(locale, attribute.nameAr, attribute.nameEn) }));
        case 'VALUE': {
            const attribute = (page.attributes ?? []).find((one) => one.id === name.valueAttributeId);

            return (attribute?.values ?? []).filter((value) => value.active).map((value) => ({ value: value.id, label: nameIn(locale, value.nameAr, value.nameEn) }));
        }
        default:
            return [];
    }
}

/** What a decided name now means, in words. */
function decided(page: ImportPage, name: ImportNameData, locale: Locale): string | null {
    if (name.decision === 'EXISTING') {
        return choices(page, name, locale, () => '').find((option) => option.value === name.targetId)?.label ?? null;
    }

    return name.decision === 'CREATE' ? nameIn(locale, name.nameAr, name.nameEn) : null;
}

function Names({ page }: { page: ImportPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const names = page.names ?? [];
    const deciding = isDeciding(page.import.state);
    const selection = useSelection(deciding ? names.map((name) => name.id) : []);
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const ticked = names.filter((name) => selection.has(name.id));
    // Ticked rows mean one item together only when they are of one kind - for values, of one attribute.
    const oneKind = ticked.length > 0 && ticked.every((name) => name.kind === ticked[0]?.kind && name.attributeKind === ticked[0]?.attributeKind && name.valueAttributeId === ticked[0]?.valueAttributeId);
    const open = (next: Dialog, trigger: HTMLElement) => {
        opener.current = trigger;
        setDialog(next);
    };
    const close = (next: boolean) => (next ? undefined : setDialog(null));
    const shown = dialog === null ? [] : names.filter((name) => dialog.ids.includes(name.id));

    if (names.length === 0) {
        return <p className="text-copy-14 text-ink-muted" data-test="names-empty">{t('catalog::admin_imports.names.empty')}</p>;
    }

    return (
        <div className="grid gap-4">
            {deciding ? (
                <div className="flex flex-wrap items-center gap-3">
                    <span className="tw-figure text-copy-14 text-ink-muted">{t('catalog::admin_imports.selected', { count: figure(locale, ticked.length) })}</span>
                    <ActionButton
                        type="button"
                        variant="secondary"
                        disabledReason={ticked.length === 0 ? t('catalog::admin_imports.none_selected') : !oneKind ? t('catalog::admin_imports.names.one_kind') : undefined}
                        onClick={(event) => open({ action: 'means', ids: ticked.map((name) => name.id) }, event.currentTarget)}
                        data-test="means-selected"
                    >
                        {t('catalog::admin_imports.names.means')}
                    </ActionButton>
                    <ActionButton
                        type="button"
                        variant="outline"
                        disabledReason={ticked.length === 0 ? t('catalog::admin_imports.none_selected') : undefined}
                        onClick={(event) => open({ action: 'refuse', ids: ticked.map((name) => name.id) }, event.currentTarget)}
                        data-test="refuse-selected"
                    >
                        {t('catalog::admin_imports.names.refuse')}
                    </ActionButton>
                </div>
            ) : null}

            <div className="material-base overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('catalog::admin_imports.part.names')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            {deciding ? (
                                <TableHead className="w-10">
                                    <SelectAll selection={selection} offered={names.length} />
                                </TableHead>
                            ) : null}
                            <TableHead>{t('catalog::admin_imports.names.column.written')}</TableHead>
                            <TableHead className="text-end">{t('catalog::admin_imports.names.column.products')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.names.column.decision')}</TableHead>
                            {deciding ? (
                                <TableHead className="w-12">
                                    <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                </TableHead>
                            ) : null}
                        </TableRow>
                    </TableHeader>
                    {KINDS.map((kind) => {
                        const rows = names.filter((name) => name.kind === kind);

                        return rows.length === 0 ? null : (
                            <TableBody key={kind} data-test={`names-${kind}`}>
                                <TableRow className="bg-surface-sunken/50 hover:bg-surface-sunken/50">
                                    <TableCell colSpan={deciding ? 5 : 3} className="text-label-13 text-ink-muted">
                                        {t(`catalog::admin_imports.names.kind.${kind}`)}
                                    </TableCell>
                                </TableRow>
                                {rows.map((name) => {
                                    const meaning = decided(page, name, locale);

                                    return (
                                        <TableRow key={name.id} data-test={`name-${name.id}`}>
                                            {deciding ? (
                                                <TableCell>
                                                    <Checkbox checked={selection.has(name.id)} onCheckedChange={(on) => selection.set(name.id, on === true)} aria-label={t('catalog::admin_imports.select_row', { name: name.written })} />
                                                </TableCell>
                                            ) : null}
                                            <TableCell>
                                                <span className="grid gap-0.5">
                                                    <bdi className="text-label-14 text-ink">{name.written}</bdi>
                                                    {name.kind === 'VALUE' && name.attribute !== null ? (
                                                        <span className="text-copy-13 text-ink-muted">{t('catalog::admin_imports.names.of_attribute', { attribute: name.attribute })}</span>
                                                    ) : null}
                                                    {name.matches > 1 ? <span className="tw-figure text-copy-13 text-ink-muted">{t('catalog::admin_imports.names.matches', { count: figure(locale, name.matches) })}</span> : null}
                                                    {name.addressTaken ? <span className="text-copy-13 text-warn">{t('catalog::admin_imports.names.address_taken')}</span> : null}
                                                </span>
                                            </TableCell>
                                            <TableCell className="tw-figure text-end">{figure(locale, name.products)}</TableCell>
                                            <TableCell>
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <Badge className={tone(name.decision === null ? 'amber-subtle' : name.decision === 'REFUSE' ? 'red-subtle' : 'green-subtle')} data-test="name-decision">
                                                        {t(`catalog::admin_imports.names.decision.${name.decision ?? 'none'}`)}
                                                    </Badge>
                                                    {meaning !== null ? <bdi className="text-copy-14 text-ink">{meaning}</bdi> : null}
                                                </span>
                                            </TableCell>
                                            {deciding ? (
                                                <TableCell className="text-end">
                                                    <span className="flex justify-end gap-2">
                                                        <Button type="button" variant="outline" size="sm" onClick={(event) => open({ action: 'means', ids: [name.id] }, event.currentTarget)} data-test="means">
                                                            {t('catalog::admin_imports.names.means')}
                                                        </Button>
                                                        {CREATED.includes(name.kind) ? (
                                                            <ActionButton
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                disabledReason={name.kind === 'VALUE' && name.valueAttributeId === null ? t('catalog::admin_imports.names.attribute_first') : undefined}
                                                                onClick={(event) => open({ action: 'create', ids: [name.id] }, event.currentTarget)}
                                                                data-test="create"
                                                            >
                                                                {t('catalog::admin_imports.names.create')}
                                                            </ActionButton>
                                                        ) : null}
                                                        <Button type="button" variant="ghost" size="sm" onClick={(event) => open({ action: 'refuse', ids: [name.id] }, event.currentTarget)} data-test="refuse">
                                                            {t('catalog::admin_imports.names.refuse')}
                                                        </Button>
                                                    </span>
                                                </TableCell>
                                            ) : null}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        );
                    })}
                </Table>
            </div>

            {dialog?.action === 'means' && shown[0] !== undefined ? (
                <MeansDialog importId={page.import.id} names={shown} options={choices(page, shown[0], locale, t)} onDone={selection.clear} onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog?.action === 'create' && shown[0] !== undefined ? <CreateDialog importId={page.import.id} name={shown[0]} onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'refuse' ? <RefuseDialog importId={page.import.id} names={shown} onDone={selection.clear} onOpenChange={close} returnFocusTo={opener} /> : null}
        </div>
    );
}

function post(importId: string, decisions: Record<string, string | null>[], setBusy: (busy: boolean) => void, onSuccess: () => void) {
    router.post(`/admin/imports/${importId}/names`, { decisions }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess });
}

function MeansDialog({
    importId,
    names,
    options,
    onDone,
    onOpenChange,
    returnFocusTo,
}: {
    importId: string;
    names: ImportNameData[];
    options: SearchOption[];
    onDone: () => void;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [target, setTarget] = useState(names.length === 1 ? (names[0]?.targetId ?? '') : '');
    const [busy, setBusy] = useState(false);
    const first = names[0];
    // A value means a value of its attribute: none is offered until the attribute is decided.
    const reason = first?.kind === 'VALUE' && first.valueAttributeId === null ? t('catalog::admin_imports.names.attribute_first') : options.length === 0 ? t('catalog::admin_imports.names.none_offered') : target === '' ? t('catalog::admin_imports.before.choose_first') : undefined;

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_imports.names.means_title')}
            description={t('catalog::admin_imports.names.means_body')}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={reason}
                    onClick={() =>
                        post(
                            importId,
                            names.map((name) => ({ name_id: name.id, decision: 'EXISTING', target_id: target })),
                            setBusy,
                            () => {
                                onDone();
                                onOpenChange(false);
                            },
                        )
                    }
                    data-test="confirm-means"
                >
                    {t('catalog::admin_imports.names.means_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-3">
                <p className="text-copy-14 text-ink">
                    <bdi>{names.map((name) => name.written).join(locale === 'ar' ? '، ' : ', ')}</bdi>
                </p>
                <SearchCombobox
                    id="means-target"
                    label={t('catalog::admin_imports.names.means_field')}
                    options={options}
                    value={target}
                    onChange={setTarget}
                    words={{ search: t('catalog::admin_imports.names.means_search'), none: (query) => t('catalog::admin_imports.names.means_none', { query }) }}
                    data-test="means-target"
                />
            </div>
        </PanelDialog>
    );
}

function CreateDialog({ importId, name, onOpenChange, returnFocusTo }: { importId: string; name: ImportNameData; onOpenChange: (open: boolean) => void; returnFocusTo: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const category = name.kind === 'CATEGORY';
    // Started from the name as written, in the language its letters are: the rest is completed here.
    const arabic = /[\u0600-\u06FF]/.test(name.written);
    const form = useForm({
        name_ar: name.nameAr ?? (arabic ? name.written : ''),
        name_en: name.nameEn ?? (arabic ? '' : name.written),
        slug_ar: name.slugAr ?? '',
        slug_en: name.slugEn ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit() {
        form.transform((data) => ({
            decisions: [
                {
                    name_id: name.id,
                    decision: 'CREATE',
                    name_ar: data.name_ar,
                    name_en: data.name_en,
                    ...(category ? { slug_ar: data.slug_ar.trim() === '' ? null : data.slug_ar, slug_en: data.slug_en.trim() === '' ? null : data.slug_en } : {}),
                },
            ],
        }));
        form.post(`/admin/imports/${importId}/names`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    }

    return (
        <PanelDialog
            wide
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_imports.names.create_title')}
            description={t('catalog::admin_imports.names.create_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} onClick={submit} data-test="confirm-create">
                    {t('catalog::admin_imports.names.create_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <p className="text-copy-14 text-ink">
                    <bdi>{name.written}</bdi>
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="create-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} error={errors.name_ar} onChange={(event) => form.setData('name_ar', toLatinDigits(event.target.value))} data-test="create-name-ar" />
                    <TextField id="create-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} error={errors.name_en} onChange={(event) => form.setData('name_en', toLatinDigits(event.target.value))} data-test="create-name-en" />
                    {category ? (
                        <>
                            <TextField id="create-slug-ar" dir="rtl" label={t('catalog::admin.field.slug_ar')} value={form.data.slug_ar} error={errors.slug_ar} onChange={(event) => form.setData('slug_ar', toLatinDigits(event.target.value))} data-test="create-slug-ar" />
                            <TextField id="create-slug-en" dir="ltr" label={t('catalog::admin.field.slug_en')} value={form.data.slug_en} error={errors.slug_en} onChange={(event) => form.setData('slug_en', toLatinDigits(event.target.value))} data-test="create-slug-en" />
                        </>
                    ) : null}
                </div>
            </div>
        </PanelDialog>
    );
}

function RefuseDialog({
    importId,
    names,
    onDone,
    onOpenChange,
    returnFocusTo,
}: {
    importId: string;
    names: ImportNameData[];
    onDone: () => void;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_imports.names.refuse_title')}
            description={t('catalog::admin_imports.names.refuse_body')}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        post(
                            importId,
                            names.map((name) => ({ name_id: name.id, decision: 'REFUSE' })),
                            setBusy,
                            () => {
                                onDone();
                                onOpenChange(false);
                            },
                        )
                    }
                    data-test="confirm-refuse"
                >
                    {t('catalog::admin_imports.names.refuse_title')}
                </ActionButton>
            }
        >
            <p className="text-copy-14 text-ink">
                <bdi>{names.map((name) => name.written).join(locale === 'ar' ? '، ' : ', ')}</bdi>
            </p>
        </PanelDialog>
    );
}

/** The names part's page: the import above its parts, this one open (ImportShell). */
export default function NamesPart(page: ImportPage) {
    return (
        <ImportShell page={page}>
            <Names page={page} />
        </ImportShell>
    );
}
