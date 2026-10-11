import { useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ImportPage, ImportProductData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';
import { SelectAll, useSelection } from './parts';
import { ImportShell } from './shell';

/*
| An import's codes (catalog.md §4.4 S11 part 2, §1.12; amendments 9(c), 11(b)): each product of the
| file whose codes the catalog holds, with the catalog's product (a link) and whether it is on sale.
| **Update · Replace Whole · Skip · New Codes…** - and, for one on sale, **Keep on Sale** or **Take Off
| Sale**, every time, no default. A product that would change a code its ready product keeps, or whose
| variants are made of other attributes, is marked, to skip or to correct in the file. A choice saves
| as it is made, for the row or for the ticked rows.
*/

const DECISIONS = ['UPDATE', 'REPLACE', 'SKIP', 'RECODE'] as const;
const SALES = ['KEEP', 'TAKE_OFF'] as const;

type Decision = { product_id: string; decision: string; sale: string | null; new_codes?: Record<string, string> };

function Codes({ page }: { page: ImportPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const rows = page.products ?? [];
    const deciding = page.import.state === 'DECIDING';
    const selection = useSelection(deciding ? rows.map((row) => row.id) : []);
    const [busy, setBusy] = useState(false);
    const [recoding, setRecoding] = useState<ImportProductData | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const ticked = rows.filter((row) => selection.has(row.id));
    const save = (decisions: Decision[], onSuccess?: () => void) =>
        router.post(`/admin/imports/${page.import.id}/codes`, { decisions }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess });
    // A decision is sent with the sale kept as it is: the two are one row's choice.
    const decide = (targets: ImportProductData[], decision: string) =>
        save(
            targets.map((row) => ({ product_id: row.id, decision, sale: row.onSale && decision !== 'SKIP' ? row.sale : null })),
            selection.clear,
        );

    if (rows.length === 0) {
        return <p className="text-copy-14 text-ink-muted" data-test="codes-empty">{t('catalog::admin_imports.codes.empty')}</p>;
    }

    return (
        <div className="grid gap-4">
            {deciding ? (
                <div className="flex flex-wrap items-center gap-3">
                    <span className="tw-figure text-copy-14 text-ink-muted">{t('catalog::admin_imports.selected', { count: figure(locale, ticked.length) })}</span>
                    {(['UPDATE', 'REPLACE', 'SKIP'] as const).map((decision) => (
                        <ActionButton
                            key={decision}
                            type="button"
                            variant="outline"
                            loading={busy}
                            disabledReason={ticked.length === 0 ? t('catalog::admin_imports.none_selected') : undefined}
                            onClick={() => decide(ticked, decision)}
                            data-test={`selected-${decision}`}
                        >
                            {t(`catalog::admin_imports.codes.decision.${decision}`)}
                        </ActionButton>
                    ))}
                </div>
            ) : null}

            <div className="material-base overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('catalog::admin_imports.part.codes')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            {deciding ? (
                                <TableHead className="w-10">
                                    <SelectAll selection={selection} offered={rows.length} disabled={busy} />
                                </TableHead>
                            ) : null}
                            <TableHead>{t('catalog::admin_imports.codes.column.product')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.codes.column.catalog')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.codes.column.codes')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.codes.column.decision')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.codes.column.sale')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => {
                            const name = nameIn(locale, row.nameAr, row.nameEn);

                            return (
                                <TableRow key={row.id} data-test={`code-row-${row.number}`}>
                                    {deciding ? (
                                        <TableCell>
                                            <Checkbox checked={selection.has(row.id)} disabled={busy} onCheckedChange={(on) => selection.set(row.id, on === true)} aria-label={t('catalog::admin_imports.select_row', { name })} />
                                        </TableCell>
                                    ) : null}
                                    <TableCell>
                                        <span className="grid gap-0.5">
                                            <bdi className="text-label-14 text-ink">{name}</bdi>
                                            <span className="tw-figure text-copy-13 text-ink-muted">{figure(locale, row.number)}</span>
                                            {row.codeChange ? <span className="text-copy-13 text-warn">{t('catalog::admin_imports.codes.code_change')}</span> : null}
                                            {row.attributeChange ? <span className="text-copy-13 text-warn">{t('catalog::admin_imports.codes.attribute_change')}</span> : null}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {row.conflictProductId !== null ? (
                                            <span className="grid gap-1">
                                                <Link href={`/admin/products/${row.conflictProductId}`} className="text-label-14 text-ink underline-offset-4 hover:underline">
                                                    {nameIn(locale, row.conflictNameAr, row.conflictNameEn) || row.conflictProductId}
                                                </Link>
                                                <Badge className={tone(row.onSale ? 'green-subtle' : 'gray-subtle')} data-test="on-sale">
                                                    {t(row.onSale ? 'catalog::admin_imports.codes.on_sale' : 'catalog::admin_imports.codes.not_on_sale')}
                                                </Badge>
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                        {row.codes.join(' · ')}
                                        {row.newCodes !== null ? <span className="block text-ink-muted">{Object.entries(row.newCodes).map(([from, to]) => `${from} → ${to}`).join(' · ')}</span> : null}
                                    </TableCell>
                                    <TableCell>
                                        {deciding ? (
                                            <NativeSelect
                                                id={`decision-${row.id}`}
                                                size="sm"
                                                aria-label={t('catalog::admin_imports.codes.decision_label', { name })}
                                                value={row.decision ?? ''}
                                                disabled={busy}
                                                onChange={(event) => {
                                                    if (event.target.value === 'RECODE') {
                                                        opener.current = event.currentTarget;
                                                        setRecoding(row);
                                                    } else if (event.target.value !== '') {
                                                        decide([row], event.target.value);
                                                    }
                                                }}
                                                data-test="decision"
                                            >
                                                <NativeSelectOption value="">{t('catalog::admin_imports.codes.decision.none')}</NativeSelectOption>
                                                {DECISIONS.map((decision) => (
                                                    <NativeSelectOption key={decision} value={decision}>
                                                        {t(`catalog::admin_imports.codes.decision.${decision}`)}
                                                    </NativeSelectOption>
                                                ))}
                                            </NativeSelect>
                                        ) : (
                                            <span className="text-copy-14">{t(`catalog::admin_imports.codes.decision.${row.decision ?? 'none'}`)}</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {row.onSale && row.decision !== null && row.decision !== 'SKIP' ? (
                                            deciding ? (
                                                <span className="grid gap-1">
                                                    <NativeSelect
                                                        id={`sale-${row.id}`}
                                                        size="sm"
                                                        aria-label={t('catalog::admin_imports.codes.sale_label', { name })}
                                                        value={row.sale ?? ''}
                                                        disabled={busy}
                                                        onChange={(event) => (event.target.value === '' ? undefined : save([{ product_id: row.id, decision: row.decision ?? '', sale: event.target.value, ...(row.decision === 'RECODE' && row.newCodes !== null ? { new_codes: row.newCodes } : {}) }]))}
                                                        data-test="sale"
                                                    >
                                                        <NativeSelectOption value="">{t('catalog::admin_imports.codes.decision.none')}</NativeSelectOption>
                                                        {SALES.map((sale) => (
                                                            <NativeSelectOption key={sale} value={sale}>
                                                                {t(`catalog::admin_imports.codes.sale.${sale}`)}
                                                            </NativeSelectOption>
                                                        ))}
                                                    </NativeSelect>
                                                    {row.sale === null ? <span className="text-copy-13 text-warn">{t('catalog::admin_imports.codes.sale_needed')}</span> : null}
                                                </span>
                                            ) : (
                                                <span className="text-copy-14">{row.sale === null ? '—' : t(`catalog::admin_imports.codes.sale.${row.sale}`)}</span>
                                            )
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>

            {recoding !== null ? (
                <RecodeDialog
                    row={recoding}
                    busy={busy}
                    onSave={(newCodes) => save([{ product_id: recoding.id, decision: 'RECODE', sale: recoding.onSale ? recoding.sale : null, new_codes: newCodes }], () => setRecoding(null))}
                    onOpenChange={(next) => (next ? undefined : setRecoding(null))}
                    returnFocusTo={opener}
                />
            ) : null}
        </div>
    );
}

/** New Codes…: a new code for each code the catalog already has - the codes left empty are not sent. */
function RecodeDialog({
    row,
    busy,
    onSave,
    onOpenChange,
    returnFocusTo,
}: {
    row: ImportProductData;
    busy: boolean;
    onSave: (newCodes: Record<string, string>) => void;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const [codes, setCodes] = useState<Record<string, string>>(row.newCodes ?? {});
    const filled = Object.fromEntries(Object.entries(codes).filter(([, to]) => to.trim() !== ''));

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_imports.codes.recode_title')}
            description={t('catalog::admin_imports.codes.recode_body')}
            busy={busy}
            confirm={
                <ActionButton loading={busy} disabledReason={Object.keys(filled).length === 0 ? t('catalog::admin_imports.before.choose_first') : undefined} onClick={() => onSave(filled)} data-test="confirm-recode">
                    {t('catalog::admin_imports.codes.recode_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {row.codes.map((code) => (
                    <TextField
                        key={code}
                        id={`recode-${code}`}
                        dir="ltr"
                        inputMode="numeric"
                        inputClassName="tw-figure font-mono"
                        label={t('catalog::admin_imports.codes.recode_field', { code })}
                        value={codes[code] ?? ''}
                        onChange={(event) => setCodes((before) => ({ ...before, [code]: toLatinDigits(event.target.value) }))}
                        data-test={`recode-${code}`}
                    />
                ))}
            </div>
        </PanelDialog>
    );
}

/** The codes part's page: the import above its parts, this one open (ImportShell). */
export default function CodesPart(page: ImportPage) {
    return (
        <ImportShell page={page}>
            <Codes page={page} />
        </ImportShell>
    );
}
