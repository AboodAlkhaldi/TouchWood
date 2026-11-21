import { lazy, Suspense, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { RichTextEditor } from '@/components/content/editor';
import { ImageBox } from '@/components/content/image-box';
import { StateBadge } from '@/components/content/list';
import { PairFields } from '@/components/content/parts';
import { markFields, TranslateAllButton, TranslateButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { cn } from 'cn';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ContentImageData, PolicyFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| New Policy and a policy's own page (flags-2026-11.md S3.7; the owner's answers of 2026-11-20): the kind (chosen once,
| read-only after), a checkbox per store the person may edit - ticked for the stores the policy serves that they hold -
| and the stores it also serves that are not theirs, read-only; then title, address, body and SEO per language as a
| page's (document-form.tsx), with Translate. **Publish** or **Save Draft**; once published, **Save Changes**; ⋯
| Unpublish… while published; Delete… only while it was never published, else shown disabled with its reason. Unpublish…
| and Delete… ask which of the person's stores (the owner's decisions of 2026-11-20: per store, as a save is), the stores
| that are not theirs shown disabled with the reason; Publish is shown disabled with its reason while the draft also
| serves a store that is not theirs (it publishes in every store it serves), unless the save splits it.
|
| A save changes the stores ticked (PolicyWriter): the form says before saving what will happen - a store unticked from
| a published policy with its words unchanged is turned off there, keeping a switched-off copy of its own as Unpublish
| does (the owner's decision of 2026-11-21; asked once more at the save), and changed words with a store it serves left
| unticked split it into a copy of their own.
*/

const ScheduleConfirm = lazy(() => import('@/components/content/schedule-confirm').then((module) => ({ default: module.ScheduleConfirm })));
const PolicyStoresConfirm = lazy(() => import('@/components/content/policy-stores-confirm').then((module) => ({ default: module.PolicyStoresConfirm })));

const LIST = '/admin/content/policies';
const PREFIX = '/policies/';

type Lang = 'ar' | 'en';

export default function Form(page: PolicyFormPageData) {
    const t = useTranslator();
    const { policy, stores } = page;
    const offered = stores.map((store) => store.id);
    const text = (language: Lang) => (language === 'ar' ? policy?.ar : policy?.en);
    const words = (key: 'title' | 'slug' | 'body' | 'seoTitle' | 'seoDescription', language: Lang) => text(language)?.[key] ?? '';
    const form = useForm({
        kind: policy?.kind ?? 'TERMS',
        // Ticked: the stores it serves that the person may change; a new one starts with their only store, or none.
        stores: policy === null ? (stores.length === 1 ? [stores[0]?.id ?? ''] : []) : policy.stores.filter((id) => offered.includes(id)),
        title_ar: words('title', 'ar'),
        title_en: words('title', 'en'),
        slug_ar: words('slug', 'ar'),
        slug_en: words('slug', 'en'),
        body_ar: words('body', 'ar'),
        body_en: words('body', 'en'),
        seo_title_ar: words('seoTitle', 'ar'),
        seo_title_en: words('seoTitle', 'en'),
        seo_description_ar: words('seoDescription', 'ar'),
        seo_description_en: words('seoDescription', 'en'),
    });
    const imageOf = (id: string | null) => (id === null ? null : (page.images[id] ?? null));
    const bodyImages = (language: Lang) => Object.fromEntries(Object.entries(text(language)?.images ?? {}).flatMap(([ref, id]) => (page.images[id] ? [[Number(ref), page.images[id]]] : [])));
    const [images, setImages] = useState<Record<Lang, Record<number, ContentImageData>>>(() => ({ ar: bodyImages('ar'), en: bodyImages('en') }));
    const [share, setShare] = useState<Record<Lang, ContentImageData | null>>(() => ({ ar: imageOf(policy?.shareAr ?? null), en: imageOf(policy?.shareEn ?? null) }));
    const [touched, setTouched] = useState(false);
    const [asking, setAsking] = useState<'unpublish' | 'delete' | 'removed' | null>(null);
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const main = useRef<HTMLButtonElement>(null);
    // Translate and the uploads work in a store the person holds the job in: the first ticked, else the first offered.
    const workStore = stores.find((store) => form.data.stores.includes(store.id)) ?? stores[0];
    const translation = useTranslation('policy', workStore?.id ?? '', page.translationReady, policy?.translationUnread === true);
    const tick = useReadTick(translation);
    const published = policy?.status === 'PUBLISHED';
    const english = (ar: string) => published && ar.trim() !== '';
    const checks = useChecks([
        { id: 'title_ar', label: t('content::admin_documents.fields.title_ar'), value: form.data.title_ar, rules: { required: true, length: { max: 150 } } },
        { id: 'title_en', label: t('content::admin_documents.fields.title_en'), value: form.data.title_en, rules: { required: published, length: { max: 150 } } },
        { id: 'seo_title_ar', label: t('content::admin_documents.fields.seo_title_ar'), value: form.data.seo_title_ar, rules: { length: { max: 70 } } },
        { id: 'seo_title_en', label: t('content::admin_documents.fields.seo_title_en'), value: form.data.seo_title_en, rules: { required: english(form.data.seo_title_ar), length: { max: 70 } } },
        { id: 'seo_description_ar', label: t('content::admin_documents.fields.seo_description_ar'), value: form.data.seo_description_ar, rules: { length: { max: 160 } } },
        { id: 'seo_description_en', label: t('content::admin_documents.fields.seo_description_en'), value: form.data.seo_description_en, rules: { required: english(form.data.seo_description_ar), length: { max: 160 } } },
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const dirty = form.isDirty || touched;
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const changed = (apply: () => void) => {
        apply();
        setTouched(true);
    };
    const set = (name: string) => (language: Lang, value: string) => form.setData(`${name}_${language}` as 'title_ar', value);
    const pairsOf = (names: string[]): Pair[] => names.map((name) => ({ name, ar: form.data[`${name}_ar` as 'title_ar'], en: form.data[`${name}_en` as 'title_en'], fill: (value: string) => form.setData(`${name}_en` as 'title_en', value) }));
    const bodyPair: Pair = { name: 'body', ar: form.data.body_ar, en: form.data.body_en, rich: true, fill: (value) => form.setData('body_en', value) };
    const pairs = [...pairsOf(['title', 'seo_title', 'seo_description']), bodyPair];
    const kindName = (kind: string) => t(`content::policies.kinds.${kind.toLowerCase()}`);
    const nameOf = (id: string) => stores.find((store) => store.id === id)?.name ?? id;
    const list = (names: string[]) => names.join(t('content::admin_policies.list_separator'));
    const address = (language: Lang) => `${page.shopRoot}/${workStore?.code ?? ''}/${language}${PREFIX}${form.data[`slug_${language}`] || t('content::admin_documents.fields.address_from_title')}`;

    // What the save will do (owner, 2026-11-20), said before it: words, images and share images changed - every box
    // PolicyWriter compares - against what the policy holds now.
    const contentOf = (data: typeof form.data, bodies: typeof images, shared: typeof share) =>
        JSON.stringify([
            (['ar', 'en'] as const).map((language) => [data[`title_${language}`], data[`slug_${language}`], data[`body_${language}`], data[`seo_title_${language}`], data[`seo_description_${language}`], Object.entries(bodies[language]).map(([ref, image]) => [ref, image.id])]),
            shared.ar?.id ?? null,
            shared.en?.id ?? null,
        ]);
    const [initial] = useState(() => contentOf(form.data, images, share));
    const contentChanged = policy !== null && contentOf(form.data, images, share) !== initial;
    const unticked = policy === null ? [] : policy.stores.filter((id) => offered.includes(id) && !form.data.stores.includes(id));
    const keeping = [...unticked.map(nameOf), ...page.otherStores];
    const splits = policy !== null && contentChanged && keeping.length > 0 && form.data.stores.length > 0;
    const removes = published && !contentChanged && unticked.length > 0;
    const storesReason = form.data.stores.length === 0 ? t('content::admin_policies.fields.stores_required') : undefined;
    const mainLabel = published ? t('content::admin_documents.save') : t('content::admin_policies.publish');

    const send = (intent: 'draft' | 'publish', read = false) => {
        form.transform((data) => ({
            ...data,
            ...markFields(translation, read),
            version: policy?.version ?? 0,
            images_ar: Object.fromEntries(Object.entries(images.ar).map(([ref, image]) => [ref, image.id])),
            images_en: Object.fromEntries(Object.entries(images.en).map(([ref, image]) => [ref, image.id])),
            share_ar: share.ar?.id ?? '',
            share_en: share.en?.id ?? '',
            intent,
        }));
        form.post(policy === null ? LIST : `${LIST}/${policy.id}`, { preserveScroll: 'errors', onSuccess: () => setTouched(false) });
    };
    const publish = () => tick.ask((read) => send('publish', read), mainLabel);
    const act = (action: 'unpublish' | 'delete', ticked: string[]) =>
        router.post(`${LIST}/${policy?.id ?? ''}/${action}`, { version: policy?.version ?? 0, stores: ticked }, {
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setAsking(null);
            },
        });
    const versionsKept = policy !== null && policy.textVersion > 0;
    // The stores it serves that are the person's: the ones Unpublish… and Delete… may tick.
    const theirs = policy === null ? [] : stores.filter((store) => policy.stores.includes(store.id));
    const others = (ticked: string[]) => [...theirs.filter((store) => !ticked.includes(store.id)).map((store) => store.name), ...page.otherStores];
    const unpublishSummary = (ticked: string[]) =>
        others(ticked).length === 0 ? t('content::admin_policies.unpublish.all') : t('content::admin_policies.unpublish.some', { stores: list(ticked.map(nameOf)), others: list(others(ticked)) });
    const deleteSummary = (ticked: string[]) => (others(ticked).length === 0 ? null : t('content::admin_policies.delete.some', { stores: list(ticked.map(nameOf)), others: list(others(ticked)) }));
    // A draft publishes in every store it serves: one not theirs blocks it, unless the save splits it into their own.
    const publishReason = policy !== null && !published && page.otherStores.length > 0 && !splits ? t('content::admin_policies.publish_blocked', { stores: list(page.otherStores) }) : undefined;

    return (
        <AdminLayout title={policy === null ? t('content::admin_policies.new') : policy.ar.title} breadcrumbs={[{ label: t('content::admin_policies.title'), href: LIST }]}>
            <form
                className="grid max-w-5xl min-w-0 gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    // A published policy's store unticked with its words unchanged, so turned off there: asked once more (S3.7).
                    checks.submit(() => (removes ? setAsking('removed') : publish()));
                }}
                data-test="policy-form"
            >
                <FormError />
                {policy === null ? null : (
                    <div className="flex flex-wrap items-center gap-2 text-copy-14 text-ink-muted">
                        <StateBadge state={policy.status} />
                        {policy.textVersion > 0 ? <span data-test="text-version">{t('content::admin_policies.fields.version', { number: policy.textVersion })}</span> : null}
                    </div>
                )}
                {policy === null ? (
                    <SelectField id="kind" className="max-w-xs" label={t('content::admin_policies.fields.kind')} error={errors.kind} value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)} data-test="kind">
                        {page.kinds.map((kind) => (
                            <NativeSelectOption key={kind} value={kind}>
                                {kindName(kind)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                ) : (
                    <p className="text-copy-14 text-ink" data-test="kind-fixed">
                        {t('content::admin_policies.fields.kind_fixed', { kind: kindName(policy.kind) })}
                    </p>
                )}
                <FieldSet className="gap-2" data-invalid={errors.stores ? true : undefined}>
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('content::admin_policies.fields.stores')}
                    </FieldLegend>
                    <FieldDescription>{t('content::admin_policies.fields.stores_helper')}</FieldDescription>
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
                    {page.otherStores.length > 0 ? (
                        <p className="text-copy-14 text-ink-muted" data-test="also-serves">
                            {t('content::admin_policies.fields.also_serves', { stores: list(page.otherStores) })}
                        </p>
                    ) : null}
                    {errors.stores ? <FieldError>{errors.stores}</FieldError> : null}
                </FieldSet>
                {/* What the save will do to the stores, as the person ticks and types (owner, 2026-11-20). */}
                <div role="status" className="grid gap-2 empty:hidden">
                    {splits ? (
                        <Note size="small" data-test="split-note">
                            {t('content::admin_policies.notices.split', { ticked: list(form.data.stores.map(nameOf)), others: list(keeping) })}
                        </Note>
                    ) : null}
                    {removes ? (
                        <Note size="small" variant="warning" data-test="removed-note">
                            {t('content::admin_policies.notices.removed', { stores: list(unticked.map(nameOf)), kind: kindName(form.data.kind) })}
                        </Note>
                    ) : null}
                </div>
                <TranslateAllButton translation={translation} pairs={pairs} />
                <PairFields name="title" labelAr={t('content::admin_documents.fields.title_ar')} labelEn={t('content::admin_documents.fields.title_en')} helper={t('content::admin_documents.fields.title_rule')} ar={form.data.title_ar} en={form.data.title_en} onChange={set('title')} errors={errors} checks={checks} translation={translation} />
                <div className="grid gap-4 sm:grid-cols-2" data-test="slugs">
                    {(['ar', 'en'] as const).map((language) => (
                        <TextField
                            key={language}
                            id={`slug_${language}`}
                            dir={language === 'ar' ? 'rtl' : 'ltr'}
                            lang={language}
                            label={language === 'ar' ? t('content::admin_documents.fields.slug_ar') : t('content::admin_documents.fields.slug_en')}
                            helper={
                                <>
                                    <span className="block break-all" dir="ltr" data-test={`address-${language}`}>
                                        {t('content::admin_documents.fields.address', { address: address(language) })}
                                    </span>
                                    <span className="block">{policy !== null && words('slug', language) !== '' && form.data[`slug_${language}`] !== words('slug', language) ? t('content::admin_documents.fields.slug_changed') : t('content::admin_documents.fields.slug_helper')}</span>
                                </>
                            }
                            error={errors[`slug_${language}`]}
                            value={form.data[`slug_${language}`]}
                            onChange={(event) => form.setData(`slug_${language}`, toLatinDigits(event.target.value))}
                            data-test={`slug_${language}`}
                        />
                    ))}
                </div>
                {(['ar', 'en'] as const).map((language) => (
                    <div key={language} className="grid min-w-0 gap-2">
                        <RichTextEditor
                            id={`body_${language}`}
                            label={language === 'ar' ? t('content::admin_documents.fields.body_ar') : t('content::admin_documents.fields.body_en')}
                            helper={language === 'en' && translation.marked.has('body') ? t('content::admin_shared.translate.marked') : t('content::admin_documents.fields.body_helper')}
                            language={language}
                            value={form.data[`body_${language}`]}
                            onChange={(value) => {
                                if (language === 'en') {
                                    translation.typed('body');
                                }

                                form.setData(`body_${language}`, value);
                            }}
                            images={images[language]}
                            onImage={(ref, image) => changed(() => setImages((was) => ({ ...was, [language]: { ...was[language], [ref]: image } })))}
                            uploadKind="policy"
                            store={workStore?.id ?? ''}
                            href={(link) => (link.startsWith('/') ? `${page.shopRoot}/${workStore?.code ?? ''}/${language}${link}` : link)}
                            check={checks.box(`body_${language}`, errors[`body_${language}`])}
                        />
                        {language === 'en' ? (
                            <div className="grid gap-2">
                                {translation.note('body') === undefined ? null : (
                                    <p className="text-copy-13 text-ink-muted" role="status" data-test="translate-note-body">
                                        {translation.note('body')}
                                    </p>
                                )}
                                <div>
                                    <TranslateButton translation={translation} pair={bodyPair} />
                                </div>
                            </div>
                        ) : null}
                    </div>
                ))}
                <Collapsible className="material-base grid min-w-0 gap-4 p-4" data-test="seo">
                    <CollapsibleTrigger className="group flex items-center justify-between gap-2 text-start text-heading-16 text-ink" data-test="seo-toggle">
                        {t('content::admin_documents.fields.seo')}
                        <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="grid min-w-0 gap-4">
                        <p className="text-copy-14 text-ink-muted">{t('content::admin_documents.fields.seo_helper')}</p>
                        {(['seo_title', 'seo_description'] as const).map((name) => {
                            const max = name === 'seo_title' ? 70 : 160;
                            const count = (language: Lang) => t('content::admin_documents.fields.count', { count: [...form.data[`${name}_${language}`]].length, max });

                            return (
                                <PairFields
                                    key={name}
                                    name={name}
                                    rows={name === 'seo_title' ? undefined : 3}
                                    labelAr={name === 'seo_title' ? t('content::admin_documents.fields.seo_title_ar') : t('content::admin_documents.fields.seo_description_ar')}
                                    labelEn={name === 'seo_title' ? t('content::admin_documents.fields.seo_title_en') : t('content::admin_documents.fields.seo_description_en')}
                                    helpers={{ ar: count('ar'), en: count('en') }}
                                    ar={form.data[`${name}_ar`]}
                                    en={form.data[`${name}_en`]}
                                    onChange={(language, value) => form.setData(`${name}_${language}`, value)}
                                    errors={errors}
                                    checks={checks}
                                    translation={translation}
                                    placeholders={name === 'seo_title' ? { ar: form.data.title_ar, en: form.data.title_en } : undefined}
                                />
                            );
                        })}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['ar', 'en'] as const).map((language) => (
                                <ImageBox
                                    key={language}
                                    id={`share_image_${language}`}
                                    label={language === 'ar' ? t('content::admin_documents.fields.share_ar') : t('content::admin_documents.fields.share_en')}
                                    helper={t('content::admin_documents.fields.share_helper')}
                                    kind="policy"
                                    store={workStore?.id ?? ''}
                                    image={share[language]}
                                    onChange={(image) => changed(() => setShare((was) => ({ ...was, [language]: image })))}
                                    size={{ width: 1200, height: 630 }}
                                    error={errors[`share_image_${language}`]}
                                />
                            ))}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                <div className="flex flex-wrap items-center gap-3">
                    <ActionButton ref={main} type="submit" loading={form.processing} disabledReason={checks.reason ?? storesReason ?? publishReason} data-test="publish">
                        {mainLabel}
                    </ActionButton>
                    {published ? null : (
                        <ActionButton type="button" variant="outline" loading={form.processing} disabledReason={storesReason} onClick={() => checks.submit(() => send('draft'))} data-test="save-draft">
                            {t('content::admin_documents.save_draft')}
                        </ActionButton>
                    )}
                    {policy === null ? null : (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <MoreButton ref={more} name={policy.ar.title} data-test="policy-more" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-48">
                                {published ? (
                                    <DropdownMenuItem variant="destructive" onSelect={() => setAsking('unpublish')} data-test="menu-unpublish">{`${t('content::admin_policies.unpublish.menu')}…`}</DropdownMenuItem>
                                ) : null}
                                {/* Once published, its versions are kept for good (owner, 2026-11-20): Delete stays in the menu, out
                                    of reach, its reason written under it (Geist; as the media's). */}
                                <DropdownMenuItem
                                    variant="destructive"
                                    aria-disabled={versionsKept || undefined}
                                    className={cn(versionsKept && 'cursor-not-allowed')}
                                    onSelect={(event) => {
                                        if (versionsKept) {
                                            event.preventDefault();

                                            return;
                                        }

                                        setAsking('delete');
                                    }}
                                    data-test="menu-delete"
                                >
                                    <span className="grid gap-0.5">
                                        <span className={versionsKept ? 'opacity-60' : undefined}>{`${t('content::admin_policies.delete.menu')}…`}</span>
                                        {versionsKept ? (
                                            <span className="text-copy-13 text-ink-muted" data-test="delete-blocked">
                                                {t('content::admin_policies.delete.blocked')}
                                            </span>
                                        ) : null}
                                    </span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </form>
            {asking === 'removed' ? (
                <Suspense fallback={null}>
                    <ScheduleConfirm
                        returnFocusTo={main}
                        title={t('content::admin_policies.removed_confirm.title')}
                        description={t('content::admin_policies.notices.removed', { stores: list(unticked.map(nameOf)), kind: kindName(form.data.kind) })}
                        label={t('content::admin_policies.removed_confirm.confirm')}
                        busy={busy || form.processing}
                        onConfirm={() => {
                            setAsking(null);
                            publish();
                        }}
                        onClose={() => setAsking(null)}
                        test="confirm-removed"
                    />
                </Suspense>
            ) : null}
            {asking === 'unpublish' || asking === 'delete' ? (
                <Suspense fallback={null}>
                    <PolicyStoresConfirm
                        returnFocusTo={more}
                        title={asking === 'unpublish' ? t('content::admin_policies.unpublish.title') : t('content::admin_policies.delete.title')}
                        description={asking === 'unpublish' ? t('content::admin_policies.unpublish.body') : t('content::admin_policies.delete.body')}
                        label={asking === 'unpublish' ? t('content::admin_policies.unpublish.confirm') : t('content::admin_policies.delete.confirm')}
                        legend={asking === 'unpublish' ? t('content::admin_policies.unpublish.stores') : t('content::admin_policies.delete.stores')}
                        stores={theirs}
                        others={page.otherStores}
                        notYours={t('content::admin_policies.unpublish.not_yours')}
                        pickReason={t('content::admin_policies.unpublish.pick')}
                        summary={asking === 'unpublish' ? unpublishSummary : deleteSummary}
                        busy={busy}
                        onConfirm={(ticked) => act(asking, ticked)}
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
