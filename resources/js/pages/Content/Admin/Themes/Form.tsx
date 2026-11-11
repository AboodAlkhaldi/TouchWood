import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { StateBadge } from '@/components/content/list';
import { sentMoment } from '@/components/content/moments';
import { PairFields } from '@/components/content/parts';
import { emptyPart, FIRST_PART, isFilled, MAX_PROMISES, partName, PARTS, slotKeys, ThemePart, type PartState } from '@/components/content/theme-parts';
import { markFields, TranslateAllButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { momentBox, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks, type Box } from '@/lib/use-checks';
import type { ContentImageData, ThemeFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W5b, a theme's page (content.md §4.4, §1.8), a Super Admin's: its name per language, its stores and dates - typed once,
| read on each store's own clock (Q1 (a)) - then **the 11 parts as `Collapsible` rows** in §1.8's order, each with its
| fields, size notes and a filled mark, and beside the form (under it on a phone) **the part being edited drawn over the
| default** at phone and computer width (theme-compare.tsx).
|
| Save Draft, or Schedule Theme - asking for the tick of §1.3 while Translate's mark stays, and refused under the dates on
| a clash with another theme (§1.8) - or Save Changes once scheduled: a live theme is edited in full and shows at the
| next page. **Preview Theme** opens the theme's first store's home with it on, for this browser alone; Stop Preview
| ends it. A linked campaign shows read-only. ⋯ End Now… while live, Back to Draft while scheduled, Delete Theme… a draft.
*/

// The comparison is drawn once the page is mounted, never on the server's render or first paint (lesson 180: React.lazy
// only behind a mounted check): the page stays under 60 KB.
const ThemeCompare = lazy(() => import('@/components/content/theme-compare').then((module) => ({ default: module.ThemeCompare })));
const ScheduleConfirm = lazy(() => import('@/components/content/schedule-confirm').then((module) => ({ default: module.ScheduleConfirm })));

const LIST = '/admin/content/themes';

function typedMoment(local: string | null): MomentInput {
    if (local === null) {
        return { none: false, date: '', time: '', typedAt: 0 };
    }

    const [date = '', time = ''] = local.split(' ');

    return { none: false, date, time: time.slice(0, 5), typedAt: 0, saved: `${date} ${time.slice(0, 5)}` };
}

/** The parts as saved, by kind, each image as its box shows it. */
function partsFrom(page: ThemeFormPageData): Record<string, PartState> {
    const parts: Record<string, PartState> = Object.fromEntries(PARTS.map((spec) => [spec.kind, emptyPart()]));

    for (const part of page.parts) {
        parts[part.part] = {
            textAr: part.textAr ?? '',
            textEn: part.textEn ?? '',
            link: part.link ?? '',
            promises: part.promises.map((each) => ({ ar: each.ar, en: each.en ?? '' })),
            images: Object.fromEntries(part.images.map((image) => [image.key, page.images[image.mediaId] ?? null])) as Record<string, ContentImageData | null>,
        };
    }

    return parts;
}

type Asking = 'end' | 'draft' | 'delete' | null;

export default function Form(page: ThemeFormPageData) {
    const { stores, themeItem: theme, campaigns, translationReady, previewing } = page;
    const t = useTranslator();
    const name = useInLanguage();
    const form = useForm({
        name_ar: theme?.nameAr ?? '',
        name_en: theme?.nameEn ?? '',
        stores: theme?.storeIds ?? stores.map((store) => store.id),
    });
    const [parts, setParts] = useState<Record<string, PartState>>(() => partsFrom(page));
    const partOf = (kind: string): PartState => parts[kind] ?? emptyPart();
    const [open, setOpen] = useState<string | null>(null);
    const [focused, setFocused] = useState(FIRST_PART.kind);
    const [starts, setStarts] = useState<MomentInput>(() => typedMoment(theme?.startsLocal ?? null));
    const [ends, setEnds] = useState<MomentInput>(() => typedMoment(theme?.endsLocal ?? null));
    const [touched, setTouched] = useState(false);
    const [asking, setAsking] = useState<Asking>(null);
    const [busy, setBusy] = useState(false);
    const [mounted, setMounted] = useState(false);

    useEffect(() => setMounted(true), []);
    const more = useRef<HTMLButtonElement>(null);
    const firstStore = stores.find((store) => form.data.stores.includes(store.id)) ?? stores[0];
    const zone = firstStore?.timezone ?? 'UTC';
    const translation = useTranslation('theme', firstStore?.id ?? '', translationReady, theme?.translationUnread === true);
    const tick = useReadTick(translation);
    const state = theme?.state ?? 'DRAFT';
    const scheduled = state !== 'DRAFT';
    const ended = state === 'ENDED';
    const live = state === 'LIVE' || (state === 'MIXED' && theme?.states.some((each) => each.state === 'LIVE') === true);
    const errors = form.errors as Record<string, string | undefined>;
    const textBoxes: Box[] = PARTS.flatMap((spec) => {
        const box = partName(spec.kind);
        const part = partOf(spec.kind);
        const words =
            spec.text > 0
                ? [
                      { id: `${box}_ar`, label: t('content::admin_themes.parts.words_ar'), value: part.textAr, rules: { required: part.textEn.trim() !== '' || (spec.link === true && part.link.trim() !== ''), length: { max: spec.text } } },
                      { id: `${box}_en`, label: t('content::admin_themes.parts.words_en'), value: part.textEn, rules: { required: scheduled && part.textAr.trim() !== '', length: { max: spec.text } } },
                  ]
                : [];
        const promises = spec.promises
            ? Array.from({ length: MAX_PROMISES }, (_, index) => {
                  const each = part.promises[index] ?? { ar: '', en: '' };

                  return [
                      { id: `${box}_promise_${index + 1}_ar`, label: t('content::admin_themes.parts.promise_ar', { number: index + 1 }), value: each.ar, rules: { required: each.en.trim() !== '', length: { max: 80 } } },
                      { id: `${box}_promise_${index + 1}_en`, label: t('content::admin_themes.parts.promise_en', { number: index + 1 }), value: each.en, rules: { required: scheduled && each.ar.trim() !== '', length: { max: 80 } } },
                  ];
              }).flat()
            : [];

        return [...words, ...promises];
    });
    const checks = useChecks([
        { id: 'name_ar', label: t('content::admin_themes.details.name_ar'), value: form.data.name_ar, rules: { required: true, length: { max: 100 } } },
        { id: 'name_en', label: t('content::admin_themes.details.name_en'), value: form.data.name_en, rules: { required: scheduled, length: { max: 100 } } },
        ...textBoxes,
        momentBox(starts, { id: 'starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const dirty = form.isDirty || touched;
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const setPart = (kind: string) => (next: PartState) => {
        setParts((was) => ({ ...was, [kind]: next }));
        setTouched(true);
        setFocused(kind);
    };
    const pairs: Pair[] = [
        { name: 'name', ar: form.data.name_ar, en: form.data.name_en, fill: (value) => form.setData('name_en', value) },
        ...PARTS.filter((spec) => spec.text > 0).map((spec) => ({ name: partName(spec.kind), ar: partOf(spec.kind).textAr, en: partOf(spec.kind).textEn, fill: (value: string) => setPart(spec.kind)({ ...partOf(spec.kind), textEn: value }) })),
    ];
    const sentParts = PARTS.filter((spec) => isFilled(partOf(spec.kind))).map((spec) => {
        const part = partOf(spec.kind);
        const images: Record<string, string> = {};

        for (const each of spec.slots) {
            for (const key of slotKeys(spec, each.slot)) {
                const image = part.images[key];

                if (image) {
                    images[key] = image.id;
                }
            }
        }

        return { part: spec.kind, textAr: part.textAr, textEn: part.textEn, link: part.link, extras: part.promises.filter((each) => each.ar.trim() !== '' || each.en.trim() !== ''), images };
    });

    const save = (intent: 'draft' | 'schedule', read = false) => {
        form.transform((data) => ({ ...data, ...markFields(translation, read), version: theme?.version ?? 0, starts_at: sentMoment(starts), ends_at: sentMoment(ends), parts: sentParts, intent }));
        form.post(theme === null ? LIST : `${LIST}/${theme.id}`, { preserveScroll: 'errors' });
    };
    const post = (action: string) =>
        router.post(
            `${LIST}/${theme?.id}/${action}`,
            { version: theme?.version ?? 0 },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setAsking(null);
                },
            },
        );
    const primary = scheduled ? t('content::admin_themes.actions.save') : t('content::admin_themes.actions.schedule');
    const focusedSpec = PARTS.find((spec) => spec.kind === focused) ?? FIRST_PART;

    return (
        <AdminLayout
            title={theme === null ? t('content::admin_themes.new') : name(theme.nameAr, theme.nameEn ?? theme.nameAr)}
            breadcrumbs={[{ label: t('content::admin_themes.title'), href: LIST }]}
            action={
                theme === null || (!live && state !== 'SCHEDULED' && state !== 'DRAFT') ? undefined : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name(theme.nameAr, theme.nameEn ?? theme.nameAr)} data-test="theme-more" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-48">
                            {state === 'SCHEDULED' ? (
                                <DropdownMenuItem onSelect={() => setAsking('draft')} data-test="menu-draft">
                                    {t('content::admin_themes.actions.draft')}
                                </DropdownMenuItem>
                            ) : null}
                            {live ? (
                                <DropdownMenuItem variant="destructive" onSelect={() => setAsking('end')} data-test="menu-end">{`${t('content::admin_themes.actions.end')}…`}</DropdownMenuItem>
                            ) : null}
                            {state === 'DRAFT' ? (
                                <DropdownMenuItem variant="destructive" onSelect={() => setAsking('delete')} data-test="menu-delete">{`${t('content::admin_themes.actions.delete')}…`}</DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )
            }
        >
            <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <form
                    className="grid min-w-0 content-start gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (!ended) {
                            checks.submit(() => tick.ask((read) => save(scheduled ? 'draft' : 'schedule', read), primary));
                        }
                    }}
                    data-test="theme-form"
                >
                    <FormError />
                    {theme === null ? null : (
                        <div className="flex flex-wrap items-center gap-2" data-test="theme-state">
                            <StateBadge state={state} />
                            {state === 'MIXED'
                                ? theme.states.map((each) => (
                                      <span key={each.storeId} className="inline-flex items-center gap-1 text-copy-13 text-ink-muted">
                                          <bdi dir="ltr" className="font-mono uppercase">
                                              {each.code}
                                          </bdi>
                                          <StateBadge state={each.state} />
                                      </span>
                                  ))
                                : null}
                        </div>
                    )}
                    {live ? <Note size="small">{t('content::admin_themes.details.live_note')}</Note> : null}
                    {ended ? (
                        <Note size="small" data-test="ended-note">
                            {t('content::admin_themes.details.ended_note')}
                        </Note>
                    ) : null}
                    {theme === null ? null : (
                        <PreviewTheme themeId={theme.id} storeCode={stores.find((store) => store.id === theme.storeIds[0])?.code ?? null} name={name(theme.nameAr, theme.nameEn ?? theme.nameAr)} previewing={previewing} dirty={dirty} />
                    )}

                    <fieldset disabled={ended} className="contents" data-test="theme-fields">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('content::admin_themes.details.title')}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid min-w-0 gap-5">
                                <TranslateAllButton translation={translation} pairs={pairs} />
                                <PairFields
                                    name="name"
                                    labelAr={t('content::admin_themes.details.name_ar')}
                                    labelEn={t('content::admin_themes.details.name_en')}
                                    helper={t('content::admin_themes.details.name_rule')}
                                    ar={form.data.name_ar}
                                    en={form.data.name_en}
                                    onChange={(language, value) => form.setData(`name_${language}`, value)}
                                    errors={errors}
                                    checks={checks}
                                    translation={translation}
                                />
                                <FieldSet className="gap-2" data-invalid={errors.stores ? true : undefined}>
                                    <FieldLegend variant="label" className="text-label-14 text-ink">
                                        {t('content::admin_themes.details.stores')}
                                    </FieldLegend>
                                    <div className="flex flex-wrap gap-4" data-test="stores">
                                        {stores.map((store) => (
                                            <Field key={store.id} orientation="horizontal" className="w-auto">
                                                <Checkbox
                                                    id={`store-${store.code}`}
                                                    checked={form.data.stores.includes(store.id)}
                                                    onCheckedChange={(on) => form.setData('stores', on === true ? [...form.data.stores, store.id] : form.data.stores.filter((id) => id !== store.id))}
                                                    data-test={`store-${store.code}`}
                                                />
                                                <FieldLabel htmlFor={`store-${store.code}`} className="font-normal">
                                                    {store.name}
                                                </FieldLabel>
                                            </Field>
                                        ))}
                                    </div>
                                    {errors.stores ? <FieldError>{errors.stores}</FieldError> : null}
                                </FieldSet>
                                <div className="grid gap-4 sm:grid-cols-2" data-test="dates">
                                    <DateTimeField
                                        id="starts"
                                        label={t('content::admin_shared.starts')}
                                        kind="start"
                                        required
                                        refusesPast
                                        value={starts}
                                        onChange={(next) => {
                                            setStarts(next);
                                            setTouched(true);
                                        }}
                                        zone={zone}
                                        check={checks.box('starts', errors.starts_at)}
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
                                            check={checks.box('ends', errors.ends_at)}
                                        />
                                        {ends.time.trim() === '00:00' ? <p className="text-copy-13 text-ink-muted">{t('content::admin_themes.details.midnight')}</p> : null}
                                    </div>
                                </div>
                                <p className="text-copy-13 text-ink-muted">{t('content::admin_themes.details.clock')}</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('content::admin_themes.parts.title')}</CardTitle>
                                <CardDescription>{t('content::admin_themes.parts.body')}</CardDescription>
                            </CardHeader>
                            <CardContent className="grid min-w-0 gap-2" data-test="parts">
                                {PARTS.map((spec) => (
                                    <ThemePart
                                        key={spec.kind}
                                        spec={spec}
                                        part={partOf(spec.kind)}
                                        onChange={setPart(spec.kind)}
                                        open={open === spec.kind}
                                        onOpenChange={(next) => {
                                            setOpen(next ? spec.kind : null);

                                            if (next) {
                                                setFocused(spec.kind);
                                            }
                                        }}
                                        store={firstStore?.id ?? ''}
                                        errors={errors}
                                        checks={checks}
                                        translation={translation}
                                        disabled={ended}
                                    />
                                ))}
                            </CardContent>
                        </Card>
                    </fieldset>

                    {campaigns.length === 0 && theme === null ? null : (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('content::admin_themes.campaigns.title')}</CardTitle>
                            </CardHeader>
                            <CardContent data-test="linked-campaigns">
                                {campaigns.length === 0 ? (
                                    <p className="text-copy-14 text-ink-muted">{t('content::admin_themes.campaigns.none')}</p>
                                ) : (
                                    <ul className="grid gap-1">
                                        {campaigns.map((campaign) => (
                                            <li key={campaign.id}>
                                                <Link href={`/admin/content/campaigns/${campaign.id}`} className="text-copy-14 text-ink underline underline-offset-4">
                                                    {name(campaign.nameAr, campaign.nameEn ?? campaign.nameAr)}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {ended ? null : (
                        <div className="flex flex-wrap gap-3">
                            <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="schedule">
                                {primary}
                            </ActionButton>
                            {scheduled ? null : (
                                <ActionButton type="button" variant="outline" loading={form.processing} onClick={() => checks.submit(() => save('draft'))} data-test="save-draft">
                                    {t('content::admin_themes.actions.save_draft')}
                                </ActionButton>
                            )}
                        </div>
                    )}
                </form>
                {mounted ? (
                    <Suspense fallback={null}>
                        <ThemeCompare spec={focusedSpec} part={partOf(focusedSpec.kind)} />
                    </Suspense>
                ) : null}
            </div>
            {asking === null || theme === null ? null : (
                <Suspense fallback={null}>
                    <ScheduleConfirm
                        returnFocusTo={more}
                        title={t(`content::admin_themes.dialog.${asking}_title`)}
                        description={t(`content::admin_themes.dialog.${asking}_body`)}
                        label={t(`content::admin_themes.actions.${asking}`)}
                        busy={busy}
                        onConfirm={() => post(asking)}
                        onClose={() => setAsking(null)}
                        test={`confirm-${asking}`}
                    />
                </Suspense>
            )}
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}

/**
 * Preview Theme (W5b `[ACCEPTED 2026-11-10]`): a signed cookie for this browser, then **the staff view** of the theme's
 * first store - the only place the shop shows a preview (§9.3d D5) - opened through Access's own View Store post, which
 * leaves the panel for the shop's home. Stop Preview takes the cookie away, here or on the shop's line. Only a saved
 * theme, as the shop draws what is saved: out of reach while the form holds changes.
 */
function PreviewTheme({ themeId, storeCode, name, previewing, dirty }: { themeId: string; storeCode: string | null; name: string; previewing: boolean; dirty: boolean }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const start = () =>
        router.post(
            `${LIST}/${themeId}/preview`,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                // The cookie is this browser's now: open the staff view, which goes to the shop.
                onSuccess: () => (storeCode === null ? setBusy(false) : router.post('/admin/staff-view', { store: storeCode }, { onFinish: () => setBusy(false) })),
                onError: () => setBusy(false),
            },
        );

    return (
        <div className="flex flex-wrap items-center gap-3" data-test="preview-theme">
            <ActionButton type="button" variant="outline" size="sm" loading={busy} disabledReason={dirty ? t('content::admin_themes.preview.save_first') : undefined} onClick={start} data-test="preview-start">
                {t('content::admin_themes.preview.start')}
            </ActionButton>
            {previewing ? (
                <>
                    <span className="text-copy-13 text-ink-muted" role="status" data-test="previewing">
                        {t('content::admin_themes.preview.on', { name })}
                    </span>
                    <Button type="button" variant="ghost" size="sm" onClick={() => router.delete(`${LIST}/preview`, { preserveScroll: true })} data-test="preview-stop">
                        {t('content::admin_themes.preview.stop')}
                    </Button>
                </>
            ) : null}
        </div>
    );
}
