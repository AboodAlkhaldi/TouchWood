import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { StateBadge } from '@/components/content/list';
import { sentMoment } from '@/components/content/moments';
import { PairFields } from '@/components/content/parts';
import { markFields, useReadTick, useTranslation } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { momentBox, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CampaignFormPageData, CampaignRunData, CampaignThemeData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W4b, a campaign's page (content.md §4.4, §1.7): four cards on one page, one column on a phone.
|
| 1. **Details**: the name per language with Translate; its stores, only those where the reader holds the job; its start
|    and end, typed once and read on each store's own clock (Q1 (a)), the 00:00 note.
| 2. **Theme**: a theme not ended, or None; linked, its parts with a filled mark, Open Theme for a Super Admin, and store by
|    store "Dates match" or "Dates differ", with Use Campaign's Dates (Q6 (a)).
| 3. **Prices**, one tab per store (campaign-prices.tsx).
| 4. **Notice**, one row per store: that store's draft notices, or None.
|
| Save Draft, then Schedule Campaign - asking for the tick of §1.3 while Translate's mark stays - or Save Changes once a
| store's run is scheduled. ⋯ End Campaign… while one is live, Cancel Campaign… while one is scheduled, each asking All
| Stores or one store and naming them; Delete Campaign… while every run is a draft. A refusal comes back to its box, else
| to the top with the sizes it names marked in the Prices tab (`FormErrors::back`, `rows.*`).
*/

// The prices editor and the theme's search are drawn once the page is mounted, never on the server's render or first
// paint (lesson 180: React.lazy only behind a mounted check; lessons.md 2026-11-02): the page stays under 60 KB.
const CampaignPrices = lazy(() => import('@/components/content/campaign-prices').then((module) => ({ default: module.CampaignPrices })));
const SearchCombobox = lazy(() => import('@/components/SearchCombobox').then((module) => ({ default: module.SearchCombobox })));
const ScopeDialog = lazy(() => import('@/components/content/scope-dialog').then((module) => ({ default: module.ScopeDialog })));
const ScheduleConfirm = lazy(() => import('@/components/content/schedule-confirm').then((module) => ({ default: module.ScheduleConfirm })));

const LIST = '/admin/content/campaigns';

/** A moment as typed ("YYYY-MM-DD HH:MM"), as the field holds it: no Now, no No End (§1.7 #1). */
function typedMoment(local: string | null): MomentInput {
    if (local === null) {
        return { none: false, date: '', time: '', typedAt: 0 };
    }

    const [date = '', time = ''] = local.split(' ');

    return { none: false, date, time: time.slice(0, 5), typedAt: 0, saved: `${date} ${time.slice(0, 5)}` };
}

type Asking = 'schedule' | 'end' | 'cancel' | 'delete' | 'dates' | null;

/** False on the server's render and first paint, true once mounted. */
function useMounted(): boolean {
    const [mounted, setMounted] = useState(false);

    useEffect(() => setMounted(true), []);

    return mounted;
}

export default function Form({ stores, campaign, runs, theme, themes, mayOpenThemes, translationReady }: CampaignFormPageData) {
    const t = useTranslator();
    const name = useInLanguage();
    const form = useForm({
        name_ar: campaign?.nameAr ?? '',
        name_en: campaign?.nameEn ?? '',
        stores: runs.length > 0 ? runs.map((run) => run.store.id) : stores.length === 1 ? stores.map((store) => store.id) : ([] as string[]),
        theme: campaign?.themeId ?? '',
    });
    const [starts, setStarts] = useState<MomentInput>(() => typedMoment(campaign?.startsLocal ?? null));
    const [ends, setEnds] = useState<MomentInput>(() => typedMoment(campaign?.endsLocal ?? null));
    const [touched, setTouched] = useState(false);
    const [asking, setAsking] = useState<Asking>(null);
    const [busy, setBusy] = useState(false);
    const mounted = useMounted();
    const more = useRef<HTMLButtonElement>(null);
    const firstStore = stores.find((store) => form.data.stores.includes(store.id)) ?? stores[0];
    const zone = firstStore?.timezone ?? 'UTC';
    const translation = useTranslation('campaign', firstStore?.id ?? '', translationReady, campaign?.translationUnread === true);
    const tick = useReadTick(translation);
    const drafts = runs.filter((run) => run.state === 'DRAFT');
    const scheduled = runs.filter((run) => run.state === 'SCHEDULED');
    const live = runs.filter((run) => run.state === 'LIVE');
    const allDrafts = campaign === null || runs.every((run) => run.state === 'DRAFT');
    const over = campaign !== null && runs.length > 0 && runs.every((run) => run.state === 'ENDED' || run.state === 'CANCELLED');
    const halfLive = campaign?.halfLive === true;
    const errorsOf = form.errors as Record<string, string | undefined>;
    const checks = useChecks([
        { id: 'name_ar', label: t('content::admin_campaigns.details.name_ar'), value: form.data.name_ar, rules: { required: true, length: { max: 100 } } },
        { id: 'name_en', label: t('content::admin_campaigns.details.name_en'), value: form.data.name_en, rules: { required: !allDrafts, length: { max: 100 } } },
        momentBox(starts, { id: 'starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const dirty = form.isDirty || touched;
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const storeName = (id: string) => stores.find((store) => store.id === id)?.name ?? runs.find((run) => run.store.id === id)?.store.name ?? id;

    const fields = (intent: 'draft' | 'schedule', read: boolean) => ({ ...form.data, ...markFields(translation, read), version: campaign?.version ?? 0, starts_at: sentMoment(starts), ends_at: sentMoment(ends), intent });
    const save = (intent: 'draft' | 'schedule', read = false) => {
        form.transform(() => fields(intent, read));
        form.post(campaign === null ? LIST : `${LIST}/${campaign.id}`, { preserveScroll: 'errors' });
    };
    const post = (path: string, data: Record<string, string | number | boolean | null>) =>
        router.post(`${LIST}/${campaign?.id}/${path}`, data, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setAsking(null);
            },
        });
    // Schedule from a form with changes saves them and schedules every draft run in one press; from a saved one it may
    // schedule one store's run alone.
    const schedule = (store: string | null) =>
        tick.ask((read) => {
            setAsking(null);

            if (campaign === null || dirty) {
                save('schedule', read);
            } else {
                post('schedule', { version: campaign.version, store, read });
            }
        }, t('content::admin_campaigns.actions.schedule'));

    const primary = campaign === null || drafts.length > 0 ? t('content::admin_campaigns.actions.schedule') : t('content::admin_campaigns.actions.save');

    return (
        <AdminLayout
            title={campaign === null ? t('content::admin_campaigns.new') : name(campaign.nameAr, campaign.nameEn ?? campaign.nameAr)}
            breadcrumbs={[{ label: t('content::admin_campaigns.title'), href: LIST }]}
            action={
                campaign === null || (live.length === 0 && scheduled.length === 0 && !allDrafts) ? undefined : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name(campaign.nameAr, campaign.nameEn ?? campaign.nameAr)} data-test="campaign-more" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-48">
                            {live.length > 0 ? (
                                <DropdownMenuItem variant="destructive" onSelect={() => setAsking('end')} data-test="menu-end">{`${t('content::admin_campaigns.actions.end')}…`}</DropdownMenuItem>
                            ) : null}
                            {scheduled.length > 0 ? (
                                <DropdownMenuItem variant="destructive" onSelect={() => setAsking('cancel')} data-test="menu-cancel">{`${t('content::admin_campaigns.actions.cancel')}…`}</DropdownMenuItem>
                            ) : null}
                            {allDrafts ? (
                                <DropdownMenuItem variant="destructive" onSelect={() => setAsking('delete')} data-test="menu-delete">{`${t('content::admin_campaigns.actions.delete')}…`}</DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )
            }
        >
            <form
                className="grid min-w-0 max-w-4xl gap-6"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (!over) {
                        checks.submit(() => (campaign === null || drafts.length > 0 ? setAsking('schedule') : tick.ask((read) => save('draft', read), primary)));
                    }
                }}
                data-test="campaign-form"
            >
                <FormError />
                {campaign === null ? null : (
                    <div className="flex flex-wrap items-center gap-2" data-test="campaign-state">
                        <StateBadge state={campaign.state} />
                        {campaign.state === 'MIXED'
                            ? runs.map((run) => (
                                  <span key={run.id} className="inline-flex items-center gap-1 text-copy-13 text-ink-muted" data-test={`run-state-${run.store.code}`}>
                                      {run.store.name}
                                      <StateBadge state={run.state} />
                                  </span>
                              ))
                            : null}
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>{t('content::admin_campaigns.details.title')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <fieldset disabled={over} className="grid min-w-0 gap-5" data-test="details">
                            {halfLive ? <Note size="small">{t('content::admin_campaigns.details.half_live')}</Note> : null}
                            <PairFields
                                name="name"
                                labelAr={t('content::admin_campaigns.details.name_ar')}
                                labelEn={t('content::admin_campaigns.details.name_en')}
                                helper={t('content::admin_campaigns.details.name_rule')}
                                ar={form.data.name_ar}
                                en={form.data.name_en}
                                onChange={(language, value) => form.setData(`name_${language}`, value)}
                                errors={errorsOf}
                                checks={checks}
                                translation={translation}
                            />
                            <FieldSet className="gap-2" data-invalid={errorsOf.stores ? true : undefined}>
                                <FieldLegend variant="label" className="text-label-14 text-ink">
                                    {t('content::admin_campaigns.details.stores')}
                                </FieldLegend>
                                <FieldDescription>{halfLive ? t('content::admin_campaigns.details.stores_locked') : t('content::admin_campaigns.details.stores_helper')}</FieldDescription>
                                <div className="flex flex-wrap gap-4" data-test="stores">
                                    {stores.map((store) => (
                                        <Field key={store.id} orientation="horizontal" className="w-auto">
                                            <Checkbox
                                                id={`store-${store.code}`}
                                                checked={form.data.stores.includes(store.id)}
                                                disabled={halfLive}
                                                onCheckedChange={(on) => form.setData('stores', on === true ? [...form.data.stores, store.id] : form.data.stores.filter((id) => id !== store.id))}
                                                data-test={`store-${store.code}`}
                                            />
                                            <FieldLabel htmlFor={`store-${store.code}`} className="font-normal">
                                                {store.name}
                                            </FieldLabel>
                                        </Field>
                                    ))}
                                </div>
                                {errorsOf.stores ? <FieldError>{errorsOf.stores}</FieldError> : null}
                            </FieldSet>
                            <div className="grid gap-4 sm:grid-cols-2" data-test="dates">
                                <DateTimeField
                                    id="starts"
                                    label={t('content::admin_shared.starts')}
                                    kind="start"
                                    required
                                    refusesPast
                                    disabled={halfLive}
                                    value={starts}
                                    onChange={(next) => {
                                        setStarts(next);
                                        setTouched(true);
                                    }}
                                    zone={zone}
                                    check={checks.box('starts', errorsOf.starts_at)}
                                />
                                <div className="grid content-start gap-2">
                                    <DateTimeField
                                        id="ends"
                                        label={t('content::admin_shared.ends')}
                                        kind="end"
                                        required
                                        value={ends}
                                        after={starts}
                                        onChange={(next) => {
                                            setEnds(next);
                                            setTouched(true);
                                        }}
                                        zone={zone}
                                        check={checks.box('ends', errorsOf.ends_at)}
                                    />
                                    {ends.time.trim() === '00:00' ? (
                                        <p className="text-copy-13 text-ink-muted" data-test="midnight">
                                            {t('content::admin_campaigns.details.midnight')}
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                            <p className="text-copy-13 text-ink-muted" data-test="clock">
                                {t('content::admin_campaigns.details.clock')}
                            </p>
                        </fieldset>
                    </CardContent>
                </Card>

                <ThemeCard
                    theme={theme}
                    themes={themes}
                    value={form.data.theme}
                    onChange={(next) => form.setData('theme', next)}
                    locked={halfLive || over}
                    error={errorsOf.theme}
                    mayOpen={mayOpenThemes}
                    storeName={storeName}
                    onUseDates={() => setAsking('dates')}
                    saved={campaign?.themeId ?? null}
                />

                {over ? null : (
                    <div className="flex flex-wrap gap-3">
                        <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="schedule">
                            {primary}
                        </ActionButton>
                        {campaign === null || drafts.length > 0 ? (
                            <ActionButton type="button" variant="outline" loading={form.processing} onClick={() => checks.submit(() => save('draft'))} data-test="save-draft">
                                {campaign !== null && drafts.length < runs.length ? t('content::admin_campaigns.actions.save') : t('content::admin_campaigns.actions.save_draft')}
                            </ActionButton>
                        ) : null}
                    </div>
                )}

                {campaign === null || runs.length === 0 ? null : (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('content::admin_campaigns.prices.title')}</CardTitle>
                                <CardDescription>{t('content::admin_campaigns.prices.body')}</CardDescription>
                            </CardHeader>
                            <CardContent className="min-w-0">
                                <Tabs defaultValue={runs[0]?.id}>
                                    <div className="max-w-full overflow-x-auto">
                                        <TabsList variant="line">
                                            {runs.map((run) => (
                                                <TabsTrigger key={run.id} value={run.id} data-test={`prices-tab-${run.store.code}`}>
                                                    {run.store.name}
                                                </TabsTrigger>
                                            ))}
                                        </TabsList>
                                    </div>
                                    {runs.map((run) => (
                                        // Every tab drawn and kept: what was typed in one stays when another is opened.
                                        <TabsContent key={run.id} value={run.id} forceMount className="pt-4 data-[state=inactive]:hidden">
                                            {mounted ? (
                                                <Suspense fallback={<Spinner />}>
                                                    <CampaignPrices run={run} version={campaign.version} />
                                                </Suspense>
                                            ) : (
                                                <Spinner />
                                            )}
                                        </TabsContent>
                                    ))}
                                </Tabs>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('content::admin_campaigns.notice.title')}</CardTitle>
                                <CardDescription>{t('content::admin_campaigns.notice.body')}</CardDescription>
                            </CardHeader>
                            <CardContent className="grid min-w-0 gap-5">
                                {runs.map((run) => (
                                    <NoticeRow key={run.id} run={run} version={campaign.version} />
                                ))}
                            </CardContent>
                        </Card>
                    </>
                )}
            </form>

            {asking === 'schedule' ? (
                <Suspense fallback={null}>
                    <ScopeDialog
                        title={t('content::admin_campaigns.dialog.schedule_title')}
                        sentence={(list) => t('content::admin_campaigns.dialog.schedule_body', { stores: list })}
                        label={t('content::admin_campaigns.actions.schedule')}
                        stores={(campaign === null || dirty ? form.data.stores.filter((id) => campaign === null || drafts.some((run) => run.store.id === id) || !runs.some((run) => run.store.id === id)) : drafts.map((run) => run.store.id)).map((id) => ({ id, name: storeName(id) }))}
                        choose={campaign !== null && !dirty}
                        destructive={false}
                        busy={busy || form.processing}
                        onConfirm={schedule}
                        onClose={() => setAsking(null)}
                        test="schedule-dialog"
                    />
                </Suspense>
            ) : null}
            {(asking === 'end' || asking === 'cancel') && campaign !== null ? (
                <Suspense fallback={null}>
                    <ScopeDialog
                        title={t(`content::admin_campaigns.dialog.${asking}_title`)}
                        sentence={(list) => t(`content::admin_campaigns.dialog.${asking}_body`, { stores: list })}
                        label={t(`content::admin_campaigns.actions.${asking}`)}
                        stores={(asking === 'end' ? live : scheduled).map((run) => ({ id: run.store.id, name: run.store.name }))}
                        choose
                        destructive
                        busy={busy}
                        onConfirm={(store) => post(asking, { store })}
                        onClose={() => setAsking(null)}
                        returnFocusTo={more}
                        test={`${asking}-dialog`}
                    />
                </Suspense>
            ) : null}
            {(asking === 'delete' || asking === 'dates') && campaign !== null ? (
                <Suspense fallback={null}>
                    <ScheduleConfirm
                        returnFocusTo={more}
                        title={asking === 'delete' ? t('content::admin_campaigns.dialog.delete_title') : t('content::admin_campaigns.theme.use_dates_title')}
                        description={asking === 'delete' ? t('content::admin_campaigns.dialog.delete_body') : t('content::admin_campaigns.theme.use_dates_body')}
                        label={asking === 'delete' ? t('content::admin_campaigns.actions.delete') : t('content::admin_campaigns.theme.use_dates')}
                        busy={busy}
                        onConfirm={() => post(asking === 'delete' ? 'delete' : 'theme-dates', { version: campaign.version })}
                        onClose={() => setAsking(null)}
                        test={`confirm-${asking}`}
                    />
                </Suspense>
            ) : null}
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}

/** The Theme card (§1.7 #7): the link, and the linked theme's parts and dates as saved. */
function ThemeCard({
    theme,
    themes,
    value,
    onChange,
    locked,
    error,
    mayOpen,
    storeName,
    onUseDates,
    saved,
}: {
    theme: CampaignThemeData | null;
    themes: CampaignFormPageData['themes'];
    value: string;
    onChange: (theme: string) => void;
    locked: boolean;
    error?: string;
    mayOpen: boolean;
    storeName: (id: string) => string;
    onUseDates: () => void;
    saved: string | null;
}) {
    const t = useTranslator();
    const name = useInLanguage();
    const mounted = useMounted();
    const options = [{ value: '', label: t('content::admin_campaigns.theme.none') }, ...themes.map((each) => ({ value: each.id, label: name(each.nameAr, each.nameEn ?? each.nameAr) }))];
    const shown = theme !== null && saved === theme.id && value === theme.id ? theme : null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('content::admin_campaigns.theme.title')}</CardTitle>
            </CardHeader>
            <CardContent className="grid min-w-0 gap-4" data-test="theme-card">
                {locked || !mounted ? (
                    <p className="text-copy-14 text-ink" data-test="theme-linked">
                        {options.find((option) => option.value === value)?.label ?? t('content::admin_campaigns.theme.none')}
                    </p>
                ) : (
                    <Suspense fallback={<Spinner />}>
                    <SearchCombobox
                        id="theme"
                        label={t('content::admin_campaigns.theme.label')}
                        helper={t('content::admin_campaigns.theme.helper')}
                        options={options}
                        value={value}
                        onChange={onChange}
                        error={error}
                        words={{ search: t('content::admin_campaigns.theme.search'), none: (query) => t('content::admin_campaigns.theme.none_match', { query }) }}
                        className="max-w-md"
                    />
                    </Suspense>
                )}
                {shown === null ? null : (
                    <div className="grid min-w-0 gap-3" data-test="theme-summary">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-label-14 text-ink">{name(shown.nameAr, shown.nameEn ?? shown.nameAr)}</span>
                            <StateBadge state={shown.state} />
                            <bdi dir="ltr" className="tw-figure text-copy-13 text-ink-muted">{`${shown.startsLocal} – ${shown.endsLocal}`}</bdi>
                        </div>
                        <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2" aria-label={t('content::admin_campaigns.theme.parts')}>
                            {shown.parts.map((part) => (
                                <li key={part.part} className="flex items-center gap-2 text-copy-13" data-test={`theme-part-${part.part}`} data-filled={part.filled ? 'true' : 'false'}>
                                    {part.filled ? <Check className="size-4 text-good" aria-hidden /> : <Minus className="size-4 text-ink-muted" aria-hidden />}
                                    <span className={part.filled ? 'text-ink' : 'text-ink-muted'}>{t(`content::admin_themes.parts.name.${part.part}`)}</span>
                                    <span className="sr-only">{part.filled ? t('content::admin_campaigns.theme.filled') : t('content::admin_campaigns.theme.empty')}</span>
                                </li>
                            ))}
                        </ul>
                        <ul className="flex flex-wrap gap-2" data-test="theme-dates">
                            {shown.dates.map((date) => (
                                <li key={date.storeId}>
                                    <Badge variant="outline" data-test={`theme-dates-${date.match ? 'match' : 'differ'}`}>{`${storeName(date.storeId)}: ${date.match ? t('content::admin_campaigns.theme.match') : t('content::admin_campaigns.theme.differ')}`}</Badge>
                                </li>
                            ))}
                        </ul>
                        {mayOpen ? (
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline" size="sm">
                                    <Link href={`/admin/content/themes/${shown.id}`} data-test="open-theme">
                                        {t('content::admin_campaigns.theme.open')}
                                    </Link>
                                </Button>
                                {shown.dates.some((date) => !date.match) && shown.state !== 'ENDED' ? (
                                    <Button type="button" variant="outline" size="sm" onClick={onUseDates} data-test="use-dates">
                                        {t('content::admin_campaigns.theme.use_dates')}
                                    </Button>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/** One store's notice (§1.7 #10): its draft notices or None, the words, the link to Offer Notices, a notice gone stale. */
function NoticeRow({ run, version }: { run: CampaignRunData; version: number }) {
    const t = useTranslator();
    const name = useInLanguage();
    const [notice, setNotice] = useState(run.notice?.id ?? '');
    const [busy, setBusy] = useState(false);
    const open = run.state === 'DRAFT' || run.state === 'SCHEDULED';
    const id = `notice-${run.store.code}`;
    const title = (each: { titleAr: string | null; titleEn: string | null }) => name(each.titleAr, each.titleEn) || '—';

    return (
        <div className="grid min-w-0 gap-2" data-test={id}>
            {open ? (
                <div className="flex flex-wrap items-end gap-3">
                    <SelectField id={id} label={t('content::admin_campaigns.notice.label', { store: run.store.name })} className="max-w-sm min-w-0 flex-1" value={notice} onChange={(event) => setNotice(event.target.value)} data-test={`${id}-select`}>
                        <NativeSelectOption value="">{t('content::admin_campaigns.notice.none')}</NativeSelectOption>
                        {run.notice !== null && !run.drafts.some((draft) => draft.id === run.notice?.id) ? <NativeSelectOption value={run.notice.id}>{title(run.notice)}</NativeSelectOption> : null}
                        {run.drafts.map((draft) => (
                            <NativeSelectOption key={draft.id} value={draft.id}>
                                {title(draft)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                    <ActionButton
                        type="button"
                        variant="outline"
                        loading={busy}
                        onClick={() =>
                            router.post(`/admin/content/campaigns/runs/${run.id}/notice`, { version, notice }, { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                        }
                        data-test={`${id}-save`}
                    >
                        {t('content::admin_campaigns.notice.save')}
                    </ActionButton>
                </div>
            ) : (
                <p className="text-copy-14 text-ink">
                    {`${run.store.name}: `}
                    {run.notice === null ? t('content::admin_campaigns.notice.none') : title(run.notice)}
                </p>
            )}
            {open && run.drafts.length === 0 && run.notice === null ? <p className="text-copy-13 text-ink-muted">{t('content::admin_campaigns.notice.no_drafts')}</p> : null}
            {open && run.notice !== null && run.notice.status !== 'DRAFT' ? (
                <Note variant="error" size="small" data-test={`${id}-stale`}>
                    {t(`content::admin_campaigns.notice.status.${run.notice.status}`)}
                </Note>
            ) : null}
            {!open && run.notice !== null && run.state !== 'CANCELLED' ? <p className="text-copy-13 text-ink-muted">{t('content::admin_campaigns.notice.sent')}</p> : null}
            {run.mayNotices ? (
                <Link href={`/admin/notices?store=${encodeURIComponent(run.store.code)}`} className="text-copy-13 text-ink-muted underline underline-offset-4 hover:text-ink" data-test={`${id}-link`}>
                    {t('content::admin_campaigns.notice.open')}
                </Link>
            ) : null}
        </div>
    );
}
