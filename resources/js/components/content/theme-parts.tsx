import { ChevronDown, Check, Minus } from 'lucide-react';
import { TextField } from '@/components/Fields';
import { ImageBox } from '@/components/content/image-box';
import { PairFields } from '@/components/content/parts';
import type { Translation } from '@/components/content/translate';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { FieldError } from '@/components/ui/field';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { Checks } from '@/lib/use-checks';
import type { ContentImageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| A theme's 11 parts (content.md §1.8), in its table's order, each a shadcn `Collapsible` row with a filled mark: its
| words per language where it takes any (with Translate), its link where it takes one, the promises' words, and its
| images by slot - per language where the artwork may carry words - each with the size to upload under its box. A part
| takes artwork and words only, never a colour (Q7 (a)).
|
| The sizes are §1.6's and §1.8's. A band's artwork (parts 3, 4, 5, 10) has no size in the spec (§9.3 #14: "settled at
| the themes step with real art"), so its box says only that it covers the band, up to Platform's 2400 wide.
*/

export type Slot = { slot: string; size?: { width: number; height: number; png?: boolean } };

export type PartSpec = {
    kind: string;
    /** The most characters of its words, each language; 0 for none. */
    text: number;
    /** Whether its words are a heading (the points and logos bands). */
    heading?: boolean;
    link?: boolean;
    promises?: boolean;
    slots: Slot[];
    perLanguage?: boolean;
    transparent?: boolean;
    band?: boolean;
};

/** The first part in §1.8's order, the comparison's until another is opened. */
export const FIRST_PART: PartSpec = { kind: 'ANNOUNCEMENT', text: 120, link: true, slots: [{ slot: 'DESKTOP', size: { width: 2400, height: 80 } }, { slot: 'PHONE', size: { width: 1200, height: 80 } }] };

export const PARTS: PartSpec[] = [
    FIRST_PART,
    { kind: 'HERO', text: 0, perLanguage: true, slots: [{ slot: 'DESKTOP', size: { width: 1600, height: 1040 } }, { slot: 'PHONE', size: { width: 1200, height: 600 } }] },
    { kind: 'PROMISES', text: 0, promises: true, band: true, slots: [{ slot: 'BAND' }] },
    { kind: 'POINTS', text: 80, heading: true, band: true, slots: [{ slot: 'BAND' }] },
    { kind: 'LOGOS', text: 80, heading: true, band: true, slots: [{ slot: 'BAND' }] },
    { kind: 'FLAG', text: 0, transparent: true, slots: [{ slot: 'FLAG', size: { width: 96, height: 96, png: true } }, { slot: 'RIBBON', size: { width: 400, height: 48, png: true } }] },
    { kind: 'BADGE', text: 24, slots: [] },
    { kind: 'LISTING', text: 0, perLanguage: true, slots: [{ slot: 'DESKTOP', size: { width: 1600, height: 300 } }, { slot: 'PHONE', size: { width: 1200, height: 300 } }] },
    { kind: 'CART', text: 120, link: true, slots: [] },
    { kind: 'FOOTER', text: 0, band: true, slots: [{ slot: 'BAND' }] },
    { kind: 'BACKDROP', text: 0, transparent: true, slots: [{ slot: 'START', size: { width: 480, height: 1600, png: true } }, { slot: 'END', size: { width: 480, height: 1600, png: true } }] },
];

export const MAX_PROMISES = 4;

export type PartState = {
    textAr: string;
    textEn: string;
    link: string;
    promises: { ar: string; en: string }[];
    /** `{SLOT}:{locale}` => the image. */
    images: Record<string, ContentImageData | null>;
};

export const emptyPart = (): PartState => ({ textAr: '', textEn: '', link: '', promises: [], images: {} });

/** Whether a part holds anything: left empty, it looks as the normal shop (Q7 (a)). */
export function isFilled(part: PartState): boolean {
    return part.textAr.trim() !== '' || part.textEn.trim() !== '' || part.promises.some((each) => each.ar.trim() !== '' || each.en.trim() !== '') || Object.values(part.images).some((image) => image !== null);
}

/** The box names a part's words post as, the server's own (`part_{kind}`). */
export const partName = (kind: string) => `part_${kind.toLowerCase()}`;

/** The image keys a part's slot takes: one, or one per language. */
export const slotKeys = (spec: PartSpec, slot: string) => (spec.perLanguage ? [`${slot}:ar`, `${slot}:en`] : [`${slot}:any`]);

export function ThemePart({
    spec,
    part,
    onChange,
    open,
    onOpenChange,
    store,
    errors,
    checks,
    translation,
    disabled,
}: {
    spec: PartSpec;
    part: PartState;
    onChange: (part: PartState) => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    store: string;
    errors: Partial<Record<string, string>>;
    checks: Checks;
    translation: Translation;
    disabled: boolean;
}) {
    const t = useTranslator();
    const filled = isFilled(part);
    const box = partName(spec.kind);
    const label = (key: string) => {
        const [slot = '', locale = 'any'] = key.split(':');
        const base = slot === 'BAND' ? 'band' : slot.toLowerCase();

        return locale === 'any' ? t(`content::admin_themes.parts.${base}`) : t(`content::admin_themes.parts.${base}_${locale}`);
    };
    const helper = (each: Slot) => [spec.band ? t('content::admin_themes.parts.band_note') : null, spec.transparent ? t('content::admin_themes.parts.transparent') : null].filter(Boolean).join(' ') || undefined;

    return (
        <Collapsible open={open} onOpenChange={onOpenChange} className="material-small min-w-0" data-test={`part-${spec.kind}`}>
            <CollapsibleTrigger asChild>
                <Button type="button" variant="ghost" className="h-auto w-full justify-between gap-3 px-3 py-3 text-start" data-test={`part-${spec.kind}-toggle`}>
                    <span className="flex min-w-0 items-center gap-2">
                        {filled ? <Check className="size-4 shrink-0 text-good" aria-hidden /> : <Minus className="size-4 shrink-0 text-ink-muted" aria-hidden />}
                        <span className="truncate text-label-14 text-ink">{t(`content::admin_themes.parts.name.${spec.kind}`)}</span>
                        <span className="text-copy-13 text-ink-muted" data-test={`part-${spec.kind}-filled`}>
                            {filled ? t('content::admin_themes.parts.filled') : t('content::admin_themes.parts.empty')}
                        </span>
                    </span>
                    <ChevronDown className={open ? 'size-4 shrink-0 rotate-180 transition-transform' : 'size-4 shrink-0 transition-transform'} aria-hidden />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <fieldset disabled={disabled} className="grid min-w-0 gap-4 px-3 pb-4">
                    <p className="text-copy-13 text-ink-muted">{t(`content::admin_themes.parts.where.${spec.kind}`)}</p>
                    {errors[box] ? <FieldError data-test={`part-${spec.kind}-error`}>{errors[box]}</FieldError> : null}
                    {spec.text > 0 ? (
                        <PairFields
                            name={box}
                            labelAr={spec.heading ? t('content::admin_themes.parts.heading_ar') : t('content::admin_themes.parts.words_ar')}
                            labelEn={spec.heading ? t('content::admin_themes.parts.heading_en') : t('content::admin_themes.parts.words_en')}
                            helper={t('content::admin_themes.parts.max', { max: spec.text })}
                            ar={part.textAr}
                            en={part.textEn}
                            onChange={(language, value) => onChange(language === 'ar' ? { ...part, textAr: value } : { ...part, textEn: value })}
                            errors={errors}
                            checks={checks}
                            translation={translation}
                        />
                    ) : null}
                    {spec.link ? (
                        <TextField
                            id={`${box}_link`}
                            dir="ltr"
                            label={t('content::admin_shared.link')}
                            helper={t('content::admin_shared.link_helper')}
                            error={errors[`${box}_link`]}
                            value={part.link}
                            onChange={(event) => onChange({ ...part, link: toLatinDigits(event.target.value) })}
                            data-test={`${box}_link`}
                        />
                    ) : null}
                    {spec.promises
                        ? Array.from({ length: MAX_PROMISES }, (_, index) => {
                              const words = part.promises[index] ?? { ar: '', en: '' };
                              const set = (next: { ar: string; en: string }) => {
                                  const promises = Array.from({ length: Math.max(part.promises.length, index + 1) }, (__, at) => (at === index ? next : (part.promises[at] ?? { ar: '', en: '' })));
                                  onChange({ ...part, promises });
                              };

                              return (
                                  <PairFields
                                      key={index}
                                      name={`${box}_promise_${index + 1}`}
                                      labelAr={t('content::admin_themes.parts.promise_ar', { number: index + 1 })}
                                      labelEn={t('content::admin_themes.parts.promise_en', { number: index + 1 })}
                                      helper={t('content::admin_themes.parts.max', { max: 80 })}
                                      ar={words.ar}
                                      en={words.en}
                                      onChange={(language, value) => set(language === 'ar' ? { ...words, ar: value } : { ...words, en: value })}
                                      errors={errors}
                                      checks={checks}
                                      translation={translation}
                                  />
                              );
                          })
                        : null}
                    {spec.perLanguage && spec.slots.length > 0 ? <p className="text-copy-13 text-ink-muted">{t('content::admin_themes.parts.words_on_art')}</p> : null}
                    {spec.slots.map((each) =>
                        slotKeys(spec, each.slot).map((key) => (
                            <ImageBox
                                key={key}
                                id={`${box}_${key.replace(':', '_').toLowerCase()}`}
                                label={label(key)}
                                kind="theme"
                                store={store}
                                image={part.images[key] ?? null}
                                onChange={(image) => onChange({ ...part, images: { ...part.images, [key]: image } })}
                                size={each.size}
                                helper={helper(each)}
                                error={key.endsWith(':en') ? errors[`${box}_${each.slot.toLowerCase()}_en`] : undefined}
                            />
                        )),
                    )}
                </fieldset>
            </CollapsibleContent>
        </Collapsible>
    );
}
