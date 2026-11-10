import { useState } from 'react';
import type { FormDataConvertible } from '@inertiajs/core';
import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { PairFields } from '@/components/content/parts';
import { TranslateAllButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks, type Box } from '@/lib/use-checks';
import type { ContentImageData, HomeSectionPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| A home section's own page (content.md §1.4, §4.4 W1 Edit): §1.4's fields by type, every pair in Arabic and English
| with Translate. A shown section is held to English beside every Arabic; a hidden one saves with Arabic alone. The hero
| takes its artwork from Banners; the logos band holds its logos, dragged into order (6 to 40).
*/

const HOME = '/admin/content/home';

type Words = { ar: string; en: string };
type Settings = Record<string, unknown>;
type Path = (string | number)[];
type Spec = { name: string; box: string; path: Path; label: string; max: number; rows?: number };
type Logo = { key: string; image: ContentImageData | null; mediaId: string; name: Words; link: string };

function at(settings: unknown, path: Path): unknown {
    return path.reduce<unknown>((value, key) => (value !== null && typeof value === 'object' ? (value as Record<string | number, unknown>)[key] : undefined), settings);
}

function put(settings: unknown, path: Path, value: unknown): Settings {
    if (path.length === 0) {
        return value as Settings;
    }

    const [key, ...rest] = path;
    // An empty map arrives from the server as [] (PHP's empty array): a named key turns it into an object, or JSON would
    // drop the key and save nothing (found by the browser test).
    const usable = settings !== null && typeof settings === 'object' && !(Array.isArray(settings) && typeof key === 'string');
    const base = (usable ? settings : typeof key === 'number' ? [] : {}) as Record<string | number, unknown>;
    const copy = (Array.isArray(base) ? [...base] : { ...base }) as Record<string | number, unknown>;
    copy[key as string] = put(base[key as string], rest, value);

    return copy as Settings;
}

function words(value: unknown): Words {
    const pair = (value ?? {}) as { ar?: string | null; en?: string | null };

    return { ar: pair.ar ?? '', en: pair.en ?? '' };
}

let next = 0;

/** The form of every type but the logos band's, which has its own page (Home/Logos), the only one dragging. */
export function SectionForm({ store, section, logos: savedLogos, icons, translationReady }: HomeSectionPageData) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props as unknown as { errors: Record<string, string | undefined> };
    const [settings, setSettings] = useState<Settings>(() => (Array.isArray(section.settings) ? {} : (section.settings as Settings)));
    const [logos, setLogos] = useState<Logo[]>(() => savedLogos.map((logo) => ({ key: `logo-${++next}`, image: logo.image, mediaId: logo.mediaId, name: { ar: logo.nameAr, en: logo.nameEn ?? '' }, link: logo.link ?? '' })));
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const translation = useTranslation('homepage', store.id, translationReady);
    const tick = useReadTick(translation);
    const type = section.type;
    const change = (path: Path, value: unknown) => {
        setSettings((was) => put(was, path, value));
        setDirty(true);
    };
    const f = (key: string) => t(`content::admin_home.fields.${key}`);
    const pairLabel = (field: string) => ({ ar: t('content::admin_home.fields.arabic', { field }), en: t('content::admin_home.fields.english', { field }) });
    const facts = Array.isArray(settings.facts) ? (settings.facts as unknown[]) : [];
    const promises = Array.isArray(settings.promises) ? (settings.promises as Settings[]) : [];

    // The pairs of this type, as HomeSectionSettings reads them: each its own name, the box a refusal names, its length.
    const button = (name: string, path: Path, box: string, label: string): Spec => ({ name, box, path: [...path, 'text'], label, max: 30 });
    const specs: Spec[] =
        type === 'HERO'
            ? [
                  { name: 'eyebrow', box: 'eyebrow', path: ['eyebrow'], label: f('eyebrow'), max: 40 },
                  { name: 'title', box: 'title', path: ['title'], label: f('title'), max: 80 },
                  { name: 'line', box: 'line', path: ['line'], label: f('line'), max: 160, rows: 2 },
                  button('main', ['main'], 'main_button', f('main_button')),
                  button('second', ['second'], 'second_button', f('second_button')),
                  ...facts.map((_, index) => ({ name: `fact${index}`, box: 'fact', path: ['facts', index], label: `${f('fact')} ${index + 1}`, max: 40 })),
              ]
            : type === 'PROMISES'
              ? promises.flatMap((_, index) => [
                    { name: `promise${index}title`, box: 'promise_title', path: ['promises', index, 'title'], label: t('content::admin_home.fields.promise', { number: index + 1 }), max: 40 },
                    { name: `promise${index}line`, box: 'promise_line', path: ['promises', index, 'line'], label: f('line'), max: 120 },
                ])
              : type === 'AUDIENCE'
                ? (['individuals', 'companies'] as const).flatMap((block) => [
                      { name: `${block}title`, box: `${block}_title`, path: [block, 'title'], label: `${f(block)} · ${f('title')}`, max: 60 },
                      { name: `${block}line`, box: `${block}_line`, path: [block, 'line'], label: `${f(block)} · ${f('line')}`, max: 160, rows: 2 },
                      button(`${block}button`, [block, 'button'], `${block}_button`, `${f(block)} · ${f('button_text')}`),
                  ])
                : type === 'POINTS'
                  ? [
                        { name: 'small_heading', box: 'small_heading', path: ['small_heading'], label: f('small_heading'), max: 40 },
                        { name: 'heading', box: 'heading', path: ['heading'], label: f('heading'), max: 80 },
                        { name: 'line', box: 'line', path: ['line'], label: f('line'), max: 160, rows: 2 },
                    ]
                  : [{ name: 'heading', box: 'heading', path: ['heading'], label: f('heading'), max: 80 }];
    const counted = type === 'CATEGORIES' ? { min: 4, max: 12 } : type === 'BEST_SELLERS' ? { min: 4, max: 20 } : null;
    const count = String(at(settings, ['count']) ?? 8);
    const boxes: Box[] = specs.flatMap((spec) => {
        const value = words(at(settings, spec.path));

        return [
            { id: `${spec.name}_ar`, label: pairLabel(spec.label).ar, value: value.ar, rules: { length: { max: spec.max } } },
            { id: `${spec.name}_en`, label: pairLabel(spec.label).en, value: value.en, rules: { required: section.isVisible && value.ar !== '', length: { max: spec.max } } },
        ];
    });
    const checks = useChecks(counted === null ? boxes : [...boxes, { id: 'count', label: f(type === 'CATEGORIES' ? 'count_tiles' : 'count_products'), value: count, rules: { required: true, number: counted } }]);
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const pairs: Pair[] = [
        ...specs.map((spec) => {
            const value = words(at(settings, spec.path));

            return { name: spec.name, ar: value.ar, en: value.en, fill: (english: string) => change([...spec.path, 'en'], english) };
        }),
        ...logos.map((logo, index) => ({ name: `logo${index}`, ar: logo.name.ar, en: logo.name.en, fill: (english: string) => setLogo(index, { ...logo, name: { ...logo.name, en: english } }) })),
    ];
    const setLogo = (index: number, logo: Logo) => {
        setLogos((was) => was.map((each, i) => (i === index ? logo : each)));
        setDirty(true);
    };
    const label = t('content::admin_home.section.save');

    const send = () =>
        router.post(
            `${HOME}/${section.id}`,
            {
                store: store.id,
                version: section.version,
                // Words only, as HomeSectionSettings reads them: plain JSON the visit sends as it is.
                settings: (counted === null ? settings : { ...settings, count: Number(count) }) as FormDataConvertible,
                logos: logos.map((logo) => ({ mediaId: logo.mediaId, nameAr: logo.name.ar, nameEn: logo.name.en, link: logo.link })),
            },
            { preserveScroll: 'errors', onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => setDirty(false) },
        );
    const pairOf = (spec: Spec) => {
        const value = words(at(settings, spec.path));
        const labels = pairLabel(spec.label);

        return (
            <PairFields
                key={spec.name}
                name={spec.name}
                rows={spec.rows}
                labelAr={labels.ar}
                labelEn={labels.en}
                ar={value.ar}
                en={value.en}
                onChange={(language, typed) => change([...spec.path, language], typed)}
                errors={{ [`${spec.name}_ar`]: errors[`${spec.box}_ar`], [`${spec.name}_en`]: errors[`${spec.box}_en`] }}
                checks={checks}
                translation={translation}
            />
        );
    };
    const linkOf = (path: Path, box: string) => (
        <TextField
            id={`${box}_link`}
            dir="ltr"
            label={f('button_link')}
            helper={t('content::admin_shared.link_helper')}
            error={errors[`${box}_link`]}
            value={String(at(settings, [...path, 'link']) ?? '')}
            onChange={(event) => change([...path, 'link'], toLatinDigits(event.target.value))}
            data-test={`${box}_link`}
        />
    );

    return (
        <AdminLayout title={t(`content::admin_home.type.${type}`)} breadcrumbs={[{ label: t('content::admin_home.title'), href: `${HOME}?store=${encodeURIComponent(store.code)}` }]}>
            <form
                className="grid max-w-4xl min-w-0 gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => (section.isVisible ? tick.ask(send, label) : send()));
                }}
                data-test="section-form"
            >
                <FormError />
                <Note size="small">{section.isVisible ? t('content::admin_home.section.shown_note') : t('content::admin_home.section.hidden_note')}</Note>
                {type === 'HERO' ? (
                    <Note size="small" action={<Link href={`/admin/content/banners?store=${encodeURIComponent(store.code)}&placement=HOME_HERO`}>{t('content::admin_home.section.open_banners')}</Link>}>
                        {t('content::admin_home.section.banners')}
                    </Note>
                ) : null}
                {type === 'POINTS' ? <Note size="small">{t('content::admin_home.section.points_note')}</Note> : null}
                {type === 'PROJECTS' ? <Note size="small">{t('content::admin_home.section.projects_note')}</Note> : null}
                {type === 'CATEGORIES' ? <Note size="small">{t('content::admin_home.section.categories_note')}</Note> : null}
                {type === 'BEST_SELLERS' ? <Note size="small">{t('content::admin_home.section.best_note')}</Note> : null}
                {pairs.length > 1 ? <TranslateAllButton translation={translation} pairs={pairs} /> : null}

                {type === 'HERO' ? (
                    <>
                        {specs.slice(0, 3).map(pairOf)}
                        {pairOf(specs[3] as Spec)}
                        {linkOf(['main'], 'main_button')}
                        {pairOf(specs[4] as Spec)}
                        {linkOf(['second'], 'second_button')}
                        <FieldSet className="gap-3" data-test="facts">
                            <FieldLegend variant="label" className="text-label-14 text-ink">
                                {f('facts')}
                            </FieldLegend>
                            <FieldDescription>{f('facts_helper')}</FieldDescription>
                            {errors.fact_ar || errors.fact_en || errors.facts ? <p className="text-copy-14 text-bad">{errors.fact_ar ?? errors.fact_en ?? errors.facts}</p> : null}
                            {specs.slice(5).map((spec, index) => (
                                <div key={spec.name} className="grid gap-2">
                                    {pairOf(spec)}
                                    <div>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => change(['facts'], facts.filter((_, i) => i !== index))}>
                                            <Trash2 aria-hidden />
                                            {f('remove')}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            {facts.length < 3 ? (
                                <div>
                                    <Button type="button" variant="outline" size="sm" onClick={() => change(['facts'], [...facts, { ar: '', en: '' }])} data-test="add-fact">
                                        <Plus aria-hidden />
                                        {f('add_fact')}
                                    </Button>
                                </div>
                            ) : null}
                        </FieldSet>
                    </>
                ) : null}

                {type === 'PROMISES' ? (
                    <FieldSet className="gap-4" data-test="promises">
                        <FieldLegend variant="label" className="text-label-14 text-ink">
                            {f('promises')}
                        </FieldLegend>
                        <FieldDescription>{f('promises_helper')}</FieldDescription>
                        {errors.promises || errors.promise_icon ? <p className="text-copy-14 text-bad">{errors.promises ?? errors.promise_icon}</p> : null}
                        {promises.map((promise, index) => (
                            <div key={index} className="material-base grid gap-4 p-4" data-test={`promise-${index}`}>
                                <SelectField id={`promise${index}icon`} className="max-w-xs" label={f('icon')} value={String(promise.icon ?? icons[0])} onChange={(event) => change(['promises', index, 'icon'], event.target.value)}>
                                    {icons.map((icon) => (
                                        <NativeSelectOption key={icon} value={icon}>
                                            {t(`content::admin_home.icon.${icon}`)}
                                        </NativeSelectOption>
                                    ))}
                                </SelectField>
                                {pairOf(specs[index * 2] as Spec)}
                                {pairOf(specs[index * 2 + 1] as Spec)}
                                <div>
                                    <Button type="button" variant="ghost" size="sm" onClick={() => change(['promises'], promises.filter((_, i) => i !== index))}>
                                        <Trash2 aria-hidden />
                                        {f('remove')}
                                    </Button>
                                </div>
                            </div>
                        ))}
                        {promises.length < 4 ? (
                            <div>
                                <Button type="button" variant="outline" size="sm" onClick={() => change(['promises'], [...promises, { icon: icons[0], title: { ar: '', en: '' }, line: { ar: '', en: '' } }])} data-test="add-promise">
                                    <Plus aria-hidden />
                                    {f('add_promise')}
                                </Button>
                            </div>
                        ) : null}
                    </FieldSet>
                ) : null}

                {type === 'AUDIENCE'
                    ? (['individuals', 'companies'] as const).map((block, index) => (
                          <FieldSet key={block} className="material-base gap-4 p-4" data-test={`block-${block}`}>
                              <FieldLegend variant="label" className="text-label-14 text-ink">
                                  {f(block)}
                              </FieldLegend>
                              {pairOf(specs[index * 3] as Spec)}
                              {pairOf(specs[index * 3 + 1] as Spec)}
                              {pairOf(specs[index * 3 + 2] as Spec)}
                              {linkOf([block, 'button'], `${block}_button`)}
                          </FieldSet>
                      ))
                    : null}

                {type === 'POINTS' || type === 'CATEGORIES' || type === 'BEST_SELLERS' || type === 'PROJECTS' || type === 'CLIENT_LOGOS' ? specs.map(pairOf) : null}

                {counted !== null ? (
                    <TextField
                        id="count"
                        inputMode="numeric"
                        dir="ltr"
                        className="max-w-48"
                        label={f(type === 'CATEGORIES' ? 'count_tiles' : 'count_products')}
                        helper={t('content::admin_home.fields.count_helper', { min: counted.min, max: counted.max })}
                        check={checks.box('count', errors.count)}
                        value={count}
                        onChange={(event) => change(['count'], toLatinDigits(event.target.value))}
                        data-test="count"
                    />
                ) : null}

                <div>
                    <ActionButton type="submit" loading={saving} disabledReason={checks.reason} data-test="save-section">
                        {label}
                    </ActionButton>
                </div>
            </form>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}

