import { useRef, useState, type ReactNode } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField, TextareaField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchCombobox, type SearchOption } from '@/components/SearchCombobox';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { ImportPage, ImportProductData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';
import { isDeciding, SelectAll, useSelection } from './parts';
import { ImportShell } from './shell';

/*
| Before bringing in (catalog.md §4.4 S11 part 3, §1.12; amendments 7(c), (d), 8(a), 8(c)): the
| changes to all the products, or the ticked ones - brand, warranty, category, search words, filter
| values, each with **Replace** · **Only Fill Empty** (· **Add**, for words and values) -, and **the
| web addresses that would collide**, each given its own. Each change applies as it is sent; the
| products' list below shows them as they will come in.
*/

type Kind = 'brand' | 'category' | 'warranty' | 'words' | 'filters';

const MODES: Record<Kind, string[]> = {
    brand: ['REPLACE', 'FILL_EMPTY'],
    category: ['REPLACE', 'FILL_EMPTY'],
    warranty: ['REPLACE', 'FILL_EMPTY'],
    words: ['ADD', 'REPLACE', 'FILL_EMPTY'],
    filters: ['ADD', 'REPLACE', 'FILL_EMPTY'],
};

function Before({ page }: { page: ImportPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const rows = page.products ?? [];
    const deciding = isDeciding(page.import.state);
    const takenCategories = page.takenCategories ?? [];
    const language = (code: string) => t(`catalog::admin_imports.before.language.${code}`);
    const selection = useSelection(deciding ? rows.map((row) => row.id) : []);
    const [own, setOwn] = useState<ImportProductData | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const colliding = rows.filter((row) => row.addressTaken.length > 0);
    const brands: SearchOption[] = (page.brands ?? []).filter((brand) => brand.active).map((brand) => ({ value: brand.id, label: nameIn(locale, brand.nameAr, brand.nameEn) }));
    const categories: SearchOption[] = (page.categories ?? []).filter((category) => category.active).map((category) => ({ value: category.id, label: locale === 'ar' ? category.pathAr : category.pathEn }));
    const filters = (page.attributes ?? []).filter((attribute) => attribute.kind === 'FILTERABLE' && attribute.active);

    return (
        <div className="grid gap-6">
            {deciding ? (
                <Card className="material-base border-0">
                    <CardHeader>
                        <CardTitle className="text-heading-16 text-ink">{t('catalog::admin_imports.before.changes')}</CardTitle>
                        <CardDescription className="text-copy-14 text-ink-muted">
                            {t('catalog::admin_imports.before.changes_body')} {t('catalog::admin_imports.selected', { count: figure(locale, selection.chosen.length) })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-6 lg:grid-cols-2">
                        <Change importId={page.import.id} kind="brand" chosen={selection.chosen} onDone={selection.clear}>
                            {(value, set) => (
                                <SearchCombobox id="change-brand" label={t('catalog::admin_imports.before.brand')} options={brands} value={value} onChange={set} words={{ search: t('catalog::admin_imports.names.means_search'), none: (query) => t('catalog::admin_imports.names.means_none', { query }) }} />
                            )}
                        </Change>
                        <Change importId={page.import.id} kind="category" chosen={selection.chosen} onDone={selection.clear}>
                            {(value, set) => (
                                <SearchCombobox id="change-category" label={t('catalog::admin_imports.before.category')} options={categories} value={value} onChange={set} words={{ search: t('catalog::admin_imports.names.means_search'), none: (query) => t('catalog::admin_imports.names.means_none', { query }) }} />
                            )}
                        </Change>
                        <Change importId={page.import.id} kind="warranty" chosen={selection.chosen} onDone={selection.clear}>
                            {(value, set) => (
                                <div className="grid gap-2">
                                    <Label htmlFor="change-warranty">{t('catalog::admin_imports.before.warranty')}</Label>
                                    <NativeSelect id="change-warranty" value={value} onChange={(event) => set(event.target.value)} data-test="change-warranty">
                                        <NativeSelectOption value="">{t('catalog::admin.choose')}</NativeSelectOption>
                                        {(page.warranties ?? [])
                                            .filter((warranty) => warranty.active)
                                            .map((warranty) => (
                                                <NativeSelectOption key={warranty.id} value={warranty.id}>
                                                    {nameIn(locale, warranty.nameAr, warranty.nameEn)}
                                                </NativeSelectOption>
                                            ))}
                                    </NativeSelect>
                                </div>
                            )}
                        </Change>
                        <Change importId={page.import.id} kind="words" chosen={selection.chosen} onDone={selection.clear}>
                            {(value, set) => (
                                <TextareaField id="change-words" label={t('catalog::admin_imports.before.words')} helper={t('catalog::admin_imports.before.words_helper')} value={value} onChange={(event) => set(toLatinDigits(event.target.value))} data-test="change-words" />
                            )}
                        </Change>
                        <Change importId={page.import.id} kind="filters" chosen={selection.chosen} onDone={selection.clear}>
                            {(value, set) => {
                                const ids = value === '' ? [] : value.split(',');
                                const toggle = (id: string, on: boolean) => set((on ? [...ids.filter((other) => other !== id), id] : ids.filter((other) => other !== id)).join(','));

                                return (
                                    <FieldSet>
                                        <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_imports.before.filters')}</FieldLegend>
                                        <div className="grid max-h-48 gap-3 overflow-y-auto">
                                            {filters.map((attribute) => (
                                                <div key={attribute.id} className="grid gap-1">
                                                    <span className="text-copy-13 text-ink-muted">{nameIn(locale, attribute.nameAr, attribute.nameEn)}</span>
                                                    <div className="flex flex-wrap gap-3">
                                                        {attribute.values
                                                            .filter((choice) => choice.active)
                                                            .map((choice) => (
                                                                <span key={choice.id} className="flex items-center gap-2">
                                                                    <Checkbox id={`filter-${choice.id}`} checked={ids.includes(choice.id)} onCheckedChange={(on) => toggle(choice.id, on === true)} />
                                                                    <Label htmlFor={`filter-${choice.id}`}>{nameIn(locale, choice.nameAr, choice.nameEn)}</Label>
                                                                </span>
                                                            ))}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </FieldSet>
                                );
                            }}
                        </Change>
                    </CardContent>
                </Card>
            ) : null}

            <section className="grid gap-3" aria-labelledby="addresses-title">
                <h2 id="addresses-title" className="text-heading-16 text-ink">
                    {t('catalog::admin_imports.before.addresses')}
                </h2>
                {takenCategories.length > 0 ? (
                    <ul className="grid gap-2" data-test="taken-categories">
                        {takenCategories.map((written) => (
                            <li key={written} className="material-small flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                                <span className="grid gap-0.5">
                                    <bdi className="text-label-14 text-ink">{written}</bdi>
                                    <span className="text-copy-13 text-warn">{t('catalog::admin_imports.names.address_taken')}</span>
                                </span>
                                <Link href={`/admin/imports/${page.import.id}?part=names`} className="text-label-14 text-ink underline underline-offset-4">
                                    {t('catalog::admin_imports.part.names')}
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : null}
                {colliding.length === 0 && takenCategories.length === 0 ? (
                    <p className="text-copy-14 text-ink-muted" data-test="addresses-empty">
                        {t('catalog::admin_imports.before.addresses_empty')}
                    </p>
                ) : colliding.length === 0 ? null : (
                    <ul className="grid gap-2" data-test="addresses">
                        {colliding.map((row) => (
                            <li key={row.id} className="material-small flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                                <span className="grid gap-0.5">
                                    <bdi className="text-label-14 text-ink">{nameIn(locale, row.nameAr, row.nameEn)}</bdi>
                                    <span className="text-copy-13 text-warn">{t('catalog::admin_imports.before.address_taken', { languages: row.addressTaken.map(language).join(' · ') })}</span>
                                </span>
                                {deciding ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={(event) => {
                                            opener.current = event.currentTarget;
                                            setOwn(row);
                                        }}
                                        data-test={`give-own-${row.number}`}
                                    >
                                        {t('catalog::admin_imports.before.give_own')}
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <div className="material-base overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('catalog::admin_imports.part.before')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            {deciding ? (
                                <TableHead className="w-10">
                                    <SelectAll selection={selection} offered={rows.length} />
                                </TableHead>
                            ) : null}
                            <TableHead className="text-end">{t('catalog::admin_imports.products.column.number')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.before.column.product')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.before.column.codes')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => {
                            const name = nameIn(locale, row.nameAr, row.nameEn);

                            return (
                                <TableRow key={row.id} data-test={`before-row-${row.number}`}>
                                    {deciding ? (
                                        <TableCell>
                                            <Checkbox checked={selection.has(row.id)} onCheckedChange={(on) => selection.set(row.id, on === true)} aria-label={t('catalog::admin_imports.select_row', { name })} />
                                        </TableCell>
                                    ) : null}
                                    <TableCell className="tw-figure text-end text-ink-muted">{figure(locale, row.number)}</TableCell>
                                    <TableCell>
                                        <bdi className="text-label-14 text-ink">{name}</bdi>
                                    </TableCell>
                                    <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                        {row.codes.join(' · ')}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>

            {own !== null ? <OwnAddressDialog importId={page.import.id} row={own} onOpenChange={(next) => (next ? undefined : setOwn(null))} returnFocusTo={opener} /> : null}
        </div>
    );
}

/**
 * One change to the products: its value, how it applies, and to all of them or the ticked ones. Sent
 * as the server's change takes it - an id, `value_ids[]` or `words[]` - with the products ticked.
 */
function Change({ importId, kind, chosen, onDone, children }: { importId: string; kind: Kind; chosen: string[]; onDone: () => void; children: (value: string, set: (value: string) => void) => ReactNode }) {
    const t = useTranslator();
    const [value, setValue] = useState('');
    // How it applies is chosen each time, never preset (amendment 7(d)).
    const [mode, setMode] = useState('');
    const [busy, setBusy] = useState(false);
    const empty = value.trim() === '';
    const reason = empty ? t('catalog::admin_imports.before.choose_first') : mode === '' ? t('catalog::admin_imports.before.mode_first') : undefined;
    const payload = (): Record<string, string | string[]> => {
        switch (kind) {
            case 'words':
                return { words: value.split('\n').map((word) => word.trim()).filter((word) => word !== '') };
            case 'filters':
                return { value_ids: value.split(',').filter((id) => id !== '') };
            default:
                return { [`${kind}_id`]: value };
        }
    };
    const apply = (productIds: string[] | null) =>
        router.post(`/admin/imports/${importId}/${kind}`, { ...payload(), mode, ...(productIds === null ? {} : { product_ids: productIds }) }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setValue('');
                setMode('');
                onDone();
            },
        });

    return (
        <div className="grid content-start gap-3" data-test={`change-${kind}`}>
            {children(value, setValue)}
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-2">
                    <Label htmlFor={`mode-${kind}`}>{t('catalog::admin_imports.before.mode')}</Label>
                    <NativeSelect id={`mode-${kind}`} size="sm" value={mode} onChange={(event) => setMode(event.target.value)} data-test={`mode-${kind}`}>
                        <NativeSelectOption value="">{t('catalog::admin_imports.before.mode_choose')}</NativeSelectOption>
                        {MODES[kind].map((option) => (
                            <NativeSelectOption key={option} value={option}>
                                {t(`catalog::admin_imports.before.modes.${option}`)}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                </div>
                <ActionButton type="button" variant="secondary" loading={busy} disabledReason={reason} onClick={() => apply(null)} data-test={`apply-all-${kind}`}>
                    {t('catalog::admin_imports.before.apply_all')}
                </ActionButton>
                <ActionButton
                    type="button"
                    variant="outline"
                    loading={busy}
                    disabledReason={reason ?? (chosen.length === 0 ? t('catalog::admin_imports.none_selected') : undefined)}
                    onClick={() => apply(chosen)}
                    data-test={`apply-selected-${kind}`}
                >
                    {t('catalog::admin_imports.before.apply_selected')}
                </ActionButton>
            </div>
        </div>
    );
}

/** A product's own web address, where the file's would collide (amendment 8(c)). */
function OwnAddressDialog({ importId, row, onOpenChange, returnFocusTo }: { importId: string; row: ImportProductData; onOpenChange: (open: boolean) => void; returnFocusTo: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm({ slug_ar: '', slug_en: '' });

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_imports.before.own_title', { name: nameIn(locale, row.nameAr, row.nameEn) })}
            description={t('catalog::admin_imports.before.address_taken', { languages: row.addressTaken.map((code) => t(`catalog::admin_imports.before.language.${code}`)).join(' · ') })}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} onClick={() => form.post(`/admin/imports/${importId}/products/${row.id}/addresses`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })} data-test="confirm-own">
                    {t('catalog::admin_imports.before.own_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {row.addressTaken.includes('ar') ? (
                    <TextField id="own-slug-ar" dir="rtl" label={t('catalog::admin.field.slug_ar')} value={form.data.slug_ar} error={form.errors.slug_ar} onChange={(event) => form.setData('slug_ar', toLatinDigits(event.target.value))} data-test="own-slug-ar" />
                ) : null}
                {row.addressTaken.includes('en') ? (
                    <TextField id="own-slug-en" dir="ltr" label={t('catalog::admin.field.slug_en')} value={form.data.slug_en} error={form.errors.slug_en} onChange={(event) => form.setData('slug_en', toLatinDigits(event.target.value))} data-test="own-slug-en" />
                ) : null}
            </div>
        </PanelDialog>
    );
}

/** The part before bringing in, as a page: the import above its parts, this one open (ImportShell). */
export default function BeforePart(page: ImportPage) {
    return (
        <ImportShell page={page}>
            <Before page={page} />
        </ImportShell>
    );
}
