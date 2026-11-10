import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PairFields, PhoneCards, StateBadge } from '@/components/content/parts';
import { sentMoment } from '@/components/content/moments';
import { useReadTick, useTranslation } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { SearchCombobox } from '@/components/SearchCombobox';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { momentBox, momentFrom, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { BarsPageData, CategoryChoiceData, SmartBarData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W2 · Bars (content.md §4.4, §1.5, §1.9): the announcement bar's form, and the smart bars' list with its dialog. Both
| tabs under `content.bar.manage`; the tab is in the address (`?tab=`). A smart bar on a campaign waits for campaigns
| (plan PR 6): until a run exists, A Campaign is shown out of reach with the reason.
*/

const LIST = '/admin/content/bars';

export default function Bars({ stores, storeCode, storeTimezone, tab, bar, smartBars, categories, translationReady }: BarsPageData) {
    const t = useTranslator();
    const store = stores.find((each) => each.code === storeCode);
    const storeId = store?.id ?? '';
    const [editing, setEditing] = useState<SmartBarData | 'new' | null>(null);
    const opener = useRef<HTMLElement | null>(null);

    const openTab = (next: string) => router.get(LIST, { store: storeCode, tab: next }, { preserveScroll: true, preserveState: true, replace: true });

    return (
        <AdminLayout
            title={t('content::admin_bars.title')}
            subtitle={t('content::admin_bars.subtitle')}
            action={
                tab === 'smart' ? (
                    <Button type="button" onClick={() => setEditing('new')} data-test="add-smart-bar">
                        {t('content::admin_bars.smart.add')}
                    </Button>
                ) : undefined
            }
        >
            <div className="grid min-w-0 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={storeCode} drop={['tab']} className="max-w-xs" />
                <Tabs value={tab} onValueChange={openTab}>
                    <TabsList variant="line">
                        <TabsTrigger value="announcement" data-test="tab-announcement">
                            {t('content::admin_bars.tabs.announcement')}
                        </TabsTrigger>
                        <TabsTrigger value="smart" data-test="tab-smart">
                            {t('content::admin_bars.tabs.smart')}
                            <span className="tw-figure text-ink-muted">{smartBars.length}</span>
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent value="announcement" className="pt-4">
                        <AnnouncementForm key={`${storeCode}-${bar.version}`} storeId={storeId} zone={storeTimezone} bar={bar} translationReady={translationReady} />
                    </TabsContent>
                    <TabsContent value="smart" className="pt-4">
                        <SmartBarsList
                            rows={smartBars}
                            categories={categories}
                            onOpen={(row, from) => {
                                opener.current = from;
                                setEditing(row);
                            }}
                            onAdd={() => setEditing('new')}
                        />
                    </TabsContent>
                </Tabs>
            </div>

            {editing === null ? null : (
                <SmartBarDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    row={editing === 'new' ? null : editing}
                    storeId={storeId}
                    zone={storeTimezone}
                    categories={categories}
                    translationReady={translationReady}
                    onClose={() => setEditing(null)}
                />
            )}
        </AdminLayout>
    );
}

function AnnouncementForm({ storeId, zone, bar, translationReady }: { storeId: string; zone: string; bar: BarsPageData['bar']; translationReady: boolean }) {
    const t = useTranslator();
    const form = useForm({ text_ar: bar.textAr ?? '', text_en: bar.textEn ?? '', link: bar.link ?? '', is_on: bar.isOn });
    const [starts, setStarts] = useState<MomentInput>(() => momentFrom(bar.startsAt, zone));
    const [ends, setEnds] = useState<MomentInput>(() => momentFrom(bar.endsAt, zone));
    const [datesTouched, setDatesTouched] = useState(false);
    const translation = useTranslation('bar', storeId, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'text_ar', label: t('content::admin_bars.bar.text_ar'), value: form.data.text_ar, rules: { required: form.data.is_on, length: { max: 120 } } },
        { id: 'text_en', label: t('content::admin_bars.bar.text_en'), value: form.data.text_en, rules: { required: form.data.is_on, length: { max: 120 } } },
        momentBox(starts, { id: 'starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const leave = useLeaveGuard(form.isDirty || datesTouched, { description: t('content::admin_shared.leave') });

    const send = () => {
        form.transform((data) => ({ ...data, store: storeId, version: bar.version, starts_at: sentMoment(starts), ends_at: sentMoment(ends) }));
        form.post(`${LIST}/announcement`, { preserveScroll: true, onSuccess: () => setDatesTouched(false) });
    };

    return (
        <form
            className="grid max-w-4xl gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                checks.submit(() => (form.data.is_on ? tick.ask(send, t('content::admin_bars.bar.save')) : send()));
            }}
            data-test="bar-form"
        >
            <Field orientation="horizontal">
                <Switch id="bar-on" checked={form.data.is_on} onCheckedChange={(on) => form.setData('is_on', on)} data-test="bar-on" />
                <div className="grid gap-1">
                    <FieldLabel htmlFor="bar-on">{t('content::admin_bars.bar.on')}</FieldLabel>
                    <FieldDescription>{t('content::admin_bars.bar.on_helper')}</FieldDescription>
                </div>
            </Field>
            <PairFields
                name="text"
                labelAr={t('content::admin_bars.bar.text_ar')}
                labelEn={t('content::admin_bars.bar.text_en')}
                helper={t('content::admin_bars.bar.text_rule')}
                ar={form.data.text_ar}
                en={form.data.text_en}
                onChange={(language, value) => form.setData(language === 'ar' ? 'text_ar' : 'text_en', value)}
                errors={errors}
                checks={checks}
                translation={translation}
            />
            <TextField
                id="link"
                dir="ltr"
                label={t('content::admin_shared.link')}
                helper={t('content::admin_shared.link_helper')}
                error={errors.link}
                value={form.data.link}
                onChange={(event) => form.setData('link', toLatinDigits(event.target.value))}
                data-test="link"
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <DateTimeField
                    id="starts"
                    label={t('content::admin_shared.starts')}
                    kind="start"
                    value={starts}
                    onChange={(next) => {
                        setStarts(next);
                        setDatesTouched(true);
                    }}
                    zone={zone}
                    check={checks.box('starts', errors.starts_at)}
                />
                <DateTimeField
                    id="ends"
                    label={t('content::admin_shared.ends')}
                    kind="end"
                    value={ends}
                    after={starts}
                    onChange={(next) => {
                        setEnds(next);
                        setDatesTouched(true);
                    }}
                    zone={zone}
                    check={checks.box('ends', errors.ends_at)}
                />
            </div>
            <div>
                <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="save-bar">
                    {t('content::admin_bars.bar.save')}
                </ActionButton>
            </div>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </form>
    );
}

function categoryPath(categories: CategoryChoiceData[], id: string | null, inLanguage: (ar: string | null, en: string | null) => string): string {
    const found = categories.find((each) => each.id === id);

    return found === undefined ? '' : inLanguage(found.pathAr, found.pathEn);
}

function SmartBarsList({ rows, categories, onOpen, onAdd }: { rows: SmartBarData[]; categories: CategoryChoiceData[]; onOpen: (row: SmartBarData, from: HTMLElement | null) => void; onAdd: () => void }) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const [deleting, setDeleting] = useState<SmartBarData | null>(null);
    const [busy, setBusy] = useState(false);

    if (rows.length === 0) {
        return (
            <Empty className="material-base" data-test="smart-empty">
                <EmptyHeader>
                    <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_bars.smart.empty')}</EmptyTitle>
                    <EmptyDescription>{t('content::admin_bars.smart.empty_body')}</EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button type="button" onClick={onAdd}>
                        {t('content::admin_bars.smart.add')}
                    </Button>
                </EmptyContent>
            </Empty>
        );
    }

    const shownOn = (row: SmartBarData) => (row.scope === 'CATEGORY' ? categoryPath(categories, row.categoryId, inLanguage) : t('content::admin_bars.smart.all_lists'));
    const when = (iso: string | null) => (iso === null ? t('content::admin_shared.none') : <Time value={iso} mode="absolute" focusable={false} />);
    const menu = (row: SmartBarData) => (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton name={inLanguage(row.messageAr, row.messageEn)} data-test={`more-${row.id}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                <DropdownMenuItem onSelect={() => onOpen(row, null)} data-test="edit-smart-bar">
                    {t('content::admin_bars.smart.menu.edit')}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(row)} data-test="delete-smart-bar">{`${t('content::admin_bars.smart.menu.delete')}…`}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                <Table>
                    <TableCaption className="sr-only">{t('content::admin_bars.tabs.smart')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t('content::admin_bars.smart.columns.message')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.shown_on')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.state')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.starts')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.ends')}</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">{t('content::admin_shared.actions')}</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.id} className="cursor-pointer" onClick={(event) => onOpen(row, event.currentTarget)} data-test={`smart-${row.id}`}>
                                <TableCell className="max-w-72 truncate text-label-14 text-ink">{inLanguage(row.messageAr, row.messageEn)}</TableCell>
                                <TableCell className="max-w-64 truncate text-ink-muted">{shownOn(row)}</TableCell>
                                <TableCell>
                                    <StateBadge state={row.state} />
                                </TableCell>
                                <TableCell className="whitespace-nowrap text-ink-muted">{when(row.startsAt)}</TableCell>
                                <TableCell className="whitespace-nowrap text-ink-muted">{when(row.endsAt)}</TableCell>
                                <TableCell className="text-end" onClick={(event) => event.stopPropagation()}>
                                    {menu(row)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <PhoneCards
                test="smart"
                cards={rows.map((row) => ({
                    id: row.id,
                    title: inLanguage(row.messageAr, row.messageEn),
                    badge: <StateBadge state={row.state} />,
                    lines: [shownOn(row)],
                    menu: menu(row),
                    onOpen: () => onOpen(row, null),
                }))}
            />

            {deleting === null ? null : (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(open) => (open ? undefined : setDeleting(null))}
                    title={t('content::admin_bars.smart.delete.title')}
                    description={t('content::admin_bars.smart.delete.body')}
                    busy={busy}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(
                                    `${LIST}/smart/${deleting.id}/delete`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        onFinish: () => {
                                            setBusy(false);
                                            setDeleting(null);
                                        },
                                    },
                                )
                            }
                            data-test="confirm-delete-smart-bar"
                        >
                            {t('content::admin_bars.smart.delete.confirm')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            )}
        </>
    );
}

function SmartBarDialog({
    row,
    storeId,
    zone,
    categories,
    translationReady,
    onClose,
}: {
    row: SmartBarData | null;
    storeId: string;
    zone: string;
    categories: CategoryChoiceData[];
    translationReady: boolean;
    onClose: () => void;
}) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const form = useForm({
        message_ar: row?.messageAr ?? '',
        message_en: row?.messageEn ?? '',
        scope: row?.scope ?? 'CATEGORY',
        category_id: row?.categoryId ?? '',
        link: row?.link ?? '',
    });
    const [starts, setStarts] = useState<MomentInput>(() => momentFrom(row?.startsAt ?? null, zone));
    const [ends, setEnds] = useState<MomentInput>(() => momentFrom(row?.endsAt ?? null, zone));
    const translation = useTranslation('bar', storeId, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'message_ar', label: t('content::admin_bars.smart.message_ar'), value: form.data.message_ar, rules: { required: true, length: { max: 100 } } },
        { id: 'message_en', label: t('content::admin_bars.smart.message_en'), value: form.data.message_en, rules: { required: true, length: { max: 100 } } },
        { id: 'category_id', label: t('content::admin_bars.smart.category'), value: form.data.category_id, rules: { required: form.data.scope === 'CATEGORY' } },
        momentBox(starts, { id: 'smart-starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'smart-ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const label = t('content::admin_bars.smart.save');

    const send = () => {
        form.transform((data) => ({ ...data, store: storeId, starts_at: sentMoment(starts), ends_at: sentMoment(ends) }));
        form.post(row === null ? `${LIST}/smart` : `${LIST}/smart/${row.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <PanelDialog
            wide
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={row === null ? t('content::admin_bars.smart.add') : t('content::admin_bars.smart.edit')}
            description={t('content::admin_bars.smart.empty_body')}
            busy={form.processing}
            confirm={
                <ActionButton type="button" loading={form.processing} disabledReason={checks.reason} onClick={() => checks.submit(() => tick.ask(send, label))} data-test="save-smart-bar">
                    {label}
                </ActionButton>
            }
        >
            <div className="grid gap-5" data-test="smart-bar-form">
                {errors.form ? (
                    <p role="alert" className="text-copy-14 text-bad">
                        {errors.form}
                    </p>
                ) : null}
                <PairFields
                    name="message"
                    labelAr={t('content::admin_bars.smart.message_ar')}
                    labelEn={t('content::admin_bars.smart.message_en')}
                    helper={t('content::admin_bars.smart.message_rule')}
                    ar={form.data.message_ar}
                    en={form.data.message_en}
                    onChange={(language, value) => form.setData(language === 'ar' ? 'message_ar' : 'message_en', value)}
                    errors={errors}
                    checks={checks}
                    translation={translation}
                />
                <FieldSet className="gap-3">
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('content::admin_bars.smart.shown_on')}
                    </FieldLegend>
                    <RadioGroup value={form.data.scope} onValueChange={(next) => form.setData('scope', next)}>
                        <Field orientation="horizontal">
                            <RadioGroupItem id="scope-CATEGORY" value="CATEGORY" data-test="scope-CATEGORY" />
                            <FieldLabel htmlFor="scope-CATEGORY" className="font-normal">
                                {t('content::admin_bars.smart.on_category')}
                            </FieldLabel>
                        </Field>
                        <Field orientation="horizontal" data-disabled>
                            <RadioGroupItem id="scope-CAMPAIGN" value="CAMPAIGN" disabled aria-describedby="scope-campaign-why" data-test="scope-CAMPAIGN" />
                            <div className="grid gap-1">
                                <FieldLabel htmlFor="scope-CAMPAIGN" className="font-normal">
                                    {t('content::admin_bars.smart.on_campaign')}
                                </FieldLabel>
                                <FieldDescription id="scope-campaign-why">{t('content::admin_bars.smart.campaign_none')}</FieldDescription>
                            </div>
                        </Field>
                    </RadioGroup>
                </FieldSet>
                {form.data.scope === 'CATEGORY' ? (
                    <SearchCombobox
                        id="category_id"
                        label={t('content::admin_bars.smart.category')}
                        helper={t('content::admin_bars.smart.category_helper')}
                        error={checks.box('category_id', errors.category_id).message}
                        options={categories.map((each) => ({ value: each.id, label: inLanguage(each.pathAr, each.pathEn) }))}
                        value={form.data.category_id}
                        onChange={(next) => form.setData('category_id', next)}
                        words={{ search: t('content::admin_bars.smart.category_search'), none: (query) => t('content::admin_bars.smart.category_none', { query }) }}
                        data-test="category"
                    />
                ) : null}
                <TextField
                    id="smart-link"
                    dir="ltr"
                    label={t('content::admin_shared.link')}
                    helper={t('content::admin_shared.link_helper')}
                    error={errors.link}
                    value={form.data.link}
                    onChange={(event) => form.setData('link', toLatinDigits(event.target.value))}
                    data-test="smart-link"
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <DateTimeField id="smart-starts" label={t('content::admin_shared.starts')} kind="start" value={starts} onChange={setStarts} zone={zone} check={checks.box('smart-starts', errors.starts_at)} />
                    <DateTimeField id="smart-ends" label={t('content::admin_shared.ends')} kind="end" value={ends} after={starts} onChange={setEnds} zone={zone} check={checks.box('smart-ends', errors.ends_at)} />
                </div>
            </div>
            {translation.dialog}
            {tick.dialog}
        </PanelDialog>
    );
}
