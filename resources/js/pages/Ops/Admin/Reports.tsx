import { useState, type KeyboardEvent } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DateRangeField } from '@/components/DateRangeField';
import { SelectField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { AdminLayout } from '@/layouts/AdminLayout';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { ReportAskedData, ReportColumnData, ReportPreviewData, ReportSheetData, ReportsPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { ReportKind } from '@/types/generated/Modules/Ops/Application/Reports';
import type { SharedProps } from '@/types/page';

/*
| O8 · Reports (ops.md §4.15): the kinds this reader may open as tabs, each its own address and store list; the
| store, the days (in that store's time) and, for Revenue, by day or month. Show Report puts the choices in the
| address and the server reads the preview with them (Q18), so a reload or a shared link shows it again. Changing
| a choice keeps the report shown, says so, and hides Download until it is shown again: a file never differs from
| what is on screen.
|
| The report itself is in the reader's account language and direction, as the server writes it; this page's own
| lines stay in the page's. Its figures are shown as they come: nothing is added up here (§4.12).
*/

const ALL = 'all';

/** Stock and Favourites stand as they are now: they have no days (§4.6). */
const ranged = (kind: ReportKind) => kind !== 'stock' && kind !== 'favourites';

/**
 * The choices as the address carries them: the store by its code, or `all`, as Ops' other pages name it (owner,
 * 2026-11-05) - the server reads it by its id (`ReportsController::storeOf`). The choices a report does not read stay out.
 */
function toAddress(kind: ReportKind, form: ReportAskedData): Record<string, string> {
    if (!ranged(kind)) {
        return { store: form.store };
    }

    return kind === 'revenue' ? { store: form.store, from: form.from, to: form.to, by: form.by } : { store: form.store, from: form.from, to: form.to };
}

/** Whether the form still asks for the report shown: only the choices that kind reads count. */
function same(kind: ReportKind, form: ReportAskedData, shown: ReportAskedData): boolean {
    const a = toAddress(kind, form);
    const b = toAddress(kind, shown);

    return Object.keys(a).every((key) => a[key] === b[key]);
}

export default function Reports({ report, reports, zones, opening, shown, preview, refusal }: ReportsPageData) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const open = reports.find((choice) => choice.kind === report);
    const [form, setForm] = useState<ReportAskedData>(opening);
    const [busy, setBusy] = useState(false);
    const changed = shown !== null && !same(report, form, shown);

    const show = () =>
        router.get(`/admin/reports/${report}`, toAddress(report, form), {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });

    return (
        <AdminLayout title={t('ops::admin_reports.title')} subtitle={t('ops::admin_reports.subtitle')}>
            <div className="grid min-w-0 gap-4">
                {/* Each kind a link to its own address, as B2B's type lists are (§4.15 O8). */}
                <Tabs value={report} activationMode="manual" className="min-w-0">
                    <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t('ops::admin_reports.kinds_label')}>
                        {reports.map((choice) => (
                            <TabsTrigger key={choice.kind} value={choice.kind} asChild className="flex-none px-3 text-label-14">
                                <Link
                                    href={`/admin/reports/${choice.kind}`}
                                    data-test={`kind-${choice.kind}`}
                                    // Enter follows the link as any link does; Space, which a link ignores, opens it too, as a
                                    // tab should (Geist's Tabs).
                                    onKeyDown={(event: KeyboardEvent<HTMLAnchorElement>) => {
                                        if (event.key === ' ') {
                                            event.preventDefault();
                                            router.visit(`/admin/reports/${choice.kind}`);
                                        }
                                    }}
                                >
                                    {t(`ops::admin_reports.kinds.${choice.kind}`)}
                                </Link>
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>

                <Card className="material-base gap-0 border-0 py-0">
                    <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3" role="group" aria-label={t('ops::admin_reports.choices_label')}>
                        <SelectField
                            id="report-store"
                            label={t('admin.store.label')}
                            value={form.store}
                            onChange={(event) => setForm({ ...form, store: event.target.value })}
                            data-test="report-store"
                        >
                            {open?.allStores ? <NativeSelectOption value={ALL}>{t('admin.store.all')}</NativeSelectOption> : null}
                            {(open?.stores ?? []).map((store) => (
                                <NativeSelectOption key={store.code} value={store.code}>
                                    {store.isOn ? store.name : t('admin.store.option_off', { store: store.name })}
                                </NativeSelectOption>
                            ))}
                        </SelectField>

                        {ranged(report) ? (
                            <DateRangeField
                                id="report-days"
                                label={t('ops::admin_reports.days')}
                                from={form.from}
                                until={form.to}
                                onChange={(from, to) => setForm({ ...form, from, to })}
                                timeZone={zones[form.store]}
                                words={{
                                    any: t('ops::admin_reports.any_date'),
                                    presets: {
                                        today: t('ops::admin_reports.preset_today'),
                                        week: t('ops::admin_reports.preset_week'),
                                        month: t('ops::admin_reports.preset_month'),
                                        monthToDate: t('ops::admin_reports.preset_month_to_date'),
                                    },
                                    helper: t('ops::admin_reports.days_helper'),
                                }}
                            />
                        ) : (
                            <p className="self-end text-copy-14 text-ink-muted sm:col-span-2" data-test="as-now">
                                {t('ops::admin_reports.as_now')}
                            </p>
                        )}

                        {report === 'revenue' ? (
                            <Field>
                                <FieldLabel id="report-by-label">{t('ops::admin_reports.by')}</FieldLabel>
                                <ToggleGroup
                                    type="single"
                                    value={form.by}
                                    onValueChange={(by) => (by === '' ? undefined : setForm({ ...form, by }))}
                                    aria-labelledby="report-by-label"
                                    className={`${SWITCH_TRACK} w-fit`}
                                    data-test="report-by"
                                >
                                    <ToggleGroupItem value="day" className={SWITCH_ITEM} data-test="by-day">
                                        {t('ops::admin_reports.by_day')}
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="month" className={SWITCH_ITEM} data-test="by-month">
                                        {t('ops::admin_reports.by_month')}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </Field>
                        ) : null}
                    </CardContent>
                    <CardFooter className="flex-wrap justify-end gap-2 border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                        {preview !== null && !changed ? (
                            <Button asChild variant="outline" data-test="download">
                                <a href={preview.downloadUrl}>{t('ops::admin_reports.download')}</a>
                            </Button>
                        ) : null}
                        <ActionButton type="button" loading={busy} onClick={show} data-test="show">
                            {t('ops::admin_reports.show')}
                        </ActionButton>
                    </CardFooter>
                </Card>

                {shown === null ? (
                    <Note variant="secondary" data-test="before">
                        {ranged(report) ? t('ops::admin_reports.before') : t('ops::admin_reports.before_now')}
                    </Note>
                ) : null}
                {refusal !== null ? (
                    <Note variant="error" alert data-test="refusal">
                        {refusal}
                    </Note>
                ) : null}
                {preview !== null && changed ? (
                    <Note variant="warning" data-test="changed">
                        {t('ops::admin_reports.changed')}
                    </Note>
                ) : null}
                {preview !== null ? <Report key={preview.downloadUrl} preview={preview} pageLocale={locale} /> : null}
            </div>
        </AdminLayout>
    );
}

function Report({ preview, pageLocale }: { preview: ReportPreviewData; pageLocale: string }) {
    const t = useTranslator();
    const [sheet, setSheet] = useState('0');

    return (
        <div className="grid min-w-0 gap-3">
            {preview.locale !== pageLocale ? (
                <p className="text-copy-13 text-ink-muted" data-test="report-language">
                    {preview.locale === 'ar' ? t('ops::admin_reports.language.ar') : t('ops::admin_reports.language.en')}
                </p>
            ) : null}
            {/* The report's own language and direction, as the server wrote it. */}
            <section dir={preview.rightToLeft ? 'rtl' : 'ltr'} lang={preview.locale} className="grid min-w-0 gap-3" data-test="report">
                <header className="grid gap-1">
                    <h2 className="text-heading-20 text-ink" data-test="report-title">
                        {preview.title}
                    </h2>
                    <p className="text-copy-14 text-ink-muted" data-test="report-subtitle">
                        {preview.subtitle}
                    </p>
                </header>
                {preview.sheets.length === 1 && preview.sheets[0] !== undefined ? (
                    <Sheet sheet={preview.sheets[0]} locale={preview.locale} pageLocale={pageLocale} index={0} />
                ) : (
                    // Kept apart by their order, never their names (§4.12).
                    <Tabs value={sheet} onValueChange={setSheet} className="min-w-0 gap-3">
                        <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t('ops::admin_reports.sheets_label')}>
                            {preview.sheets.map((each, index) => (
                                <TabsTrigger key={index} value={String(index)} data-test={`sheet-${index}`} className="flex-none px-3 text-label-14">
                                    {each.name}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        {preview.sheets.map((each, index) => (
                            <TabsContent key={index} value={String(index)} className="min-w-0">
                                <Sheet sheet={each} locale={preview.locale} pageLocale={pageLocale} index={index} />
                            </TabsContent>
                        ))}
                    </Tabs>
                )}
            </section>
        </div>
    );
}

function Sheet({ sheet, locale, pageLocale, index }: { sheet: ReportSheetData; locale: string; pageLocale: string; index: number }) {
    const t = useTranslator();
    // This page's own lines keep the page's language and direction inside a report written in the other.
    const own = { lang: pageLocale, dir: pageLocale === 'ar' ? 'rtl' : 'ltr' } as const;

    return (
        <div className="grid min-w-0 gap-3" data-test={`sheet-body-${index}`}>
            {sheet.notes.length > 0 ? (
                <Note variant="secondary" data-test="notes">
                    {sheet.notes.map((note, at) => (
                        <span key={at} className="block">
                            {note}
                        </span>
                    ))}
                </Note>
            ) : null}
            {sheet.rows.length === 0 ? (
                <Empty className="material-base" data-test="empty">
                    <EmptyHeader>
                        <EmptyTitle className="text-heading-16 text-ink" {...own}>
                            {t('ops::admin_reports.empty')}
                        </EmptyTitle>
                    </EmptyHeader>
                </Empty>
            ) : (
                <>
                    <div tabIndex={0} className="material-base max-w-full min-w-0 overflow-x-auto" data-test="table">
                        <Table>
                            <TableCaption className="sr-only">{sheet.name}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    {sheet.columns.map((column) => (
                                        <TableHead key={column.key} className={figureColumn(column) ? 'text-end' : undefined}>
                                            {column.label}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sheet.rows.map((row, at) => (
                                    <TableRow key={at} data-test="row">
                                        {sheet.columns.map((column, place) => (
                                            <TableCell key={column.key} className={figureColumn(column) ? 'text-end whitespace-nowrap' : 'text-ink-muted'}>
                                                <Value value={row[place] ?? null} column={column} locale={locale} />
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                            {sheet.totals.length > 0 ? (
                                <TableFooter className="bg-surface-sunken">
                                    {sheet.totals.map((row, at) => (
                                        <TableRow key={at} className="font-semibold" data-test="total">
                                            {sheet.columns.map((column, place) => (
                                                <TableCell key={column.key} className={figureColumn(column) ? 'text-end whitespace-nowrap' : undefined}>
                                                    <Value value={row[place] ?? null} column={column} locale={locale} />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableFooter>
                            ) : null}
                        </Table>
                    </div>
                    {sheet.more ? (
                        <p className="text-copy-13 text-ink-muted" data-test="more-rows" {...own}>
                            {t('ops::admin_reports.more')}
                        </p>
                    ) : null}
                </>
            )}
        </div>
    );
}

/** Counts and amounts line up at the end of their cells. */
function figureColumn(column: ReportColumnData): boolean {
    return column.type === 'count' || column.type === 'money';
}

/**
 * One cell as it came (§4.12): null as "—", a count in Latin digits, and an amount, a day or a month exactly as it
 * stands - negative or not a decimal at all - kept left to right inside a right-to-left report. Text is text: a
 * shopper's search may begin with "=".
 */
function Value({ value, column, locale }: { value: string | number | null; column: ReportColumnData; locale: string }) {
    if (value === null) {
        return <span className="text-ink-subtle">—</span>;
    }

    if (column.type === 'count' && typeof value === 'number') {
        return (
            <bdi dir="ltr" className="tw-figure">
                {figure(locale, value)}
            </bdi>
        );
    }

    if (column.type === 'text') {
        return <bdi>{String(value)}</bdi>;
    }

    return (
        <bdi dir="ltr" className="tw-figure">
            {String(value)}
        </bdi>
    );
}
