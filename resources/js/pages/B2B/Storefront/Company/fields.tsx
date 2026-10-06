import { lazy, Suspense } from 'react';
import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { cn } from 'cn';
import { Description } from '@/components/geist-only/Description';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldContent, FieldDescription, FieldLabel, FieldLegend, FieldSet, FieldTitle } from '@/components/ui/field';
import { InputGroupAddon, InputGroupText } from '@/components/ui/input-group';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CompanyFieldRuleData, CompanySavedAddressData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';

/*
| How a field of the company page says where it stands (b2b.md §4.5, amendments 16(a) and 22(a)),
| and the address picker (amendments 16(f) and 22(c)).
|
| **At the field's end**, inside it: "Saving…" with shadcn's Spinner while its save is out - the field
| stays editable - then "✓ Saved" once the server holds it (shadcn's input-group addons). **Under
| it**, its rule in grey (shadcn's field description), which turns red, with the reason, when the
| field must be fixed: a value not valid once it is left, a server refusal, the last decision's mark.
| The edge is plain, or red (aria-invalid); there is no yellow and no green edge. A value not valid is
| never sent. The rules come from the server, the same numbers it checks, and both trim the same
| characters (amendment 17(a)).
|
| The saved addresses are shadcn's choice cards (field-choice-card), each naming its store, with no
| store headings; past six, a searchable list instead (Geist's Choicebox: "cap at 4-6 tiles"; the
| owner, 2026-10-04).
*/

/** A value as the server keeps it: line breaks as one, and nothing at either end (17(a)). */
export function normal(value: string): string {
    return value.replace(/\r\n?/g, '\n').trim();
}

/** Where a field stands, as the person sees it. */
export type Look = 'idle' | 'unsaved' | 'saving' | 'saved' | 'invalid' | 'refused';

type Translate = (key: string, values?: Record<string, string | number>) => string;

/**
 * A field's label as the subject of a sentence that names it (Geist: "Project name is required."):
 * in English in sentence case; Arabic has no case, and its words quote the label as it is.
 */
export function subjectOf(label: string, locale: 'ar' | 'en'): string {
    return locale === 'ar' || label === '' ? label : label.charAt(0) + label.slice(1).toLowerCase();
}

/**
 * What is wrong with a value, in words that name the field - or null when the server would take it.
 * Written as the domain checks it: spaces at either end do not count, and a length is in
 * characters, not bytes.
 */
export function check(value: string, rule: CompanyFieldRuleData | undefined, required: boolean, t: Translate, field: string, locale: 'ar' | 'en'): string | null {
    const text = normal(value);
    const figure = figures(locale);

    if (text === '') {
        return required ? t('b2b::company.check.required', { field }) : null;
    }

    if (rule === undefined) {
        return null;
    }

    if (rule.oneLine ? /\p{Cc}/u.test(text) : /[^\P{Cc}\n]/u.test(text)) {
        return rule.oneLine ? t('b2b::company.check.one_line', { field }) : t('b2b::company.check.lines', { field });
    }

    const length = [...text].length;

    if (length < rule.min) {
        return t('b2b::company.check.min', { field, count: figure(rule.min) });
    }

    if (length > rule.max) {
        return t('b2b::company.check.max', { field, count: figure(rule.max) });
    }

    if (rule.characters !== null && !new RegExp(rule.characters, 'u').test(text)) {
        return t('b2b::company.check.characters', { field });
    }

    return null;
}

/** Numbers in a sentence, in Latin digits on every page (frontend.md §1.8). */
function figures(locale: 'ar' | 'en'): (value: number) => string {
    const format = new Intl.NumberFormat(intlLocale(locale));

    return (value) => format.format(value);
}

/** A field's rule, said in grey under it while nothing is wrong (amendment 22(a)). */
export function ruleOf(rule: CompanyFieldRuleData | undefined, t: Translate, locale: 'ar' | 'en'): string | null {
    if (rule === undefined) {
        return null;
    }

    const figure = figures(locale);

    if (rule.characters !== null) {
        return t('b2b::company.rule.code', { min: figure(rule.min), max: figure(rule.max) });
    }

    return rule.min > 0 ? t('b2b::company.rule.range', { min: figure(rule.min), max: figure(rule.max) }) : t('b2b::company.rule.up_to', { max: figure(rule.max) });
}

/** Whether the field must be fixed: its edge and its line go red. */
export function mustFix(look: Look): boolean {
    return look === 'invalid' || look === 'refused';
}

/**
 * "Saving…" or "✓ Saved" at the field's end, inside it (shadcn's input-group-spinner and
 * input-group-icon). Shown to the eye; FieldState says it to a screen reader.
 */
export function SaveMark({ look, align = 'inline-end' }: { look: Look; align?: 'inline-end' | 'block-end' }) {
    const t = useTranslator();

    if (look !== 'saving' && look !== 'saved') {
        return null;
    }

    return (
        <InputGroupAddon align={align} aria-hidden="true" data-test="save-mark" className={align === 'block-end' ? 'justify-end' : undefined}>
            <SaveWords look={look} t={t} />
        </InputGroupAddon>
    );
}

/** The same words beside a control that is not an input group (the company type's select). */
export function SaveBeside({ look }: { look: Look }) {
    const t = useTranslator();

    return look !== 'saving' && look !== 'saved' ? null : (
        <span aria-hidden="true" className="inline-flex shrink-0 items-center gap-2 text-label-13 text-ink-muted" data-test="save-mark">
            <SaveWords look={look} t={t} />
        </span>
    );
}

function SaveWords({ look, t }: { look: 'saving' | 'saved'; t: Translate }) {
    return look === 'saving' ? (
        <>
            <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
            <InputGroupText className="text-label-13">{t('b2b::company.saving')}</InputGroupText>
        </>
    ) : (
        <>
            <Check aria-hidden="true" className="text-good" />
            <InputGroupText className="text-label-13">{t('b2b::company.saved')}</InputGroupText>
        </>
    );
}

/**
 * The line under a field: its rule in grey, or in red what must be fixed - `problem`, given by the
 * field: a value not valid, a refusal, the last decision's mark (shadcn's field description and
 * field error). Always there, so a word appearing in it never moves what is below. A refusal is
 * said at once; anything else waits its turn. The save state, shown at the field's end, is said here
 * to a screen reader.
 */
export function FieldState({ id, look, rule, problem }: { id: string; look: Look; rule: string | null; problem: string | null }) {
    const t = useTranslator();
    const fix = problem !== null;

    return (
        <p
            id={id}
            role={look === 'refused' ? 'alert' : 'status'}
            aria-live={look === 'refused' ? undefined : 'polite'}
            data-test="field-state"
            data-look={look}
            className={cn('min-h-4.5 text-copy-13', fix ? 'text-bad' : 'text-ink-muted')}
        >
            {fix ? problem : (rule ?? '')}
            {look === 'saving' ? <span className="sr-only"> {t('b2b::company.saving')}</span> : look === 'saved' ? <span className="sr-only"> {t('b2b::company.saved')}</span> : null}
        </p>
    );
}

/** Choice cards up to this many; past it, a searchable list (the owner, 2026-10-04). */
const TILES = 6;

// The searchable list, and the command palette under it, load only for an account past six saved
// addresses - not with every company page (frontend.md §5, the page budget).
const SearchCombobox = lazy(() => import('@/components/SearchCombobox').then((module) => ({ default: module.SearchCombobox })));

/**
 * The account's saved addresses to pick one from (amendment 16(f)): any store's, each as its store's
 * format writes it and naming its store. One the format no longer accepts is shown and cannot be
 * picked, its reason written in it. With none saved, the section is shadcn's Empty; **Add Address**
 * opens the account's Addresses tab, which brings the person back here once one is saved
 * (access.md amendment 51) - in the form, after the saves still waiting (`onAdd`, amendment 17(i)).
 *
 * What the company or the draft keeps is a copy - shown above the list, as it was when picked. The
 * address it came from shows picked only while it still reads as that copy: edited since in the
 * address book, it shows unpicked, with a note, and picking it again takes the new text (17(b)).
 */
export function AddressPicker({
    addresses,
    pickedId,
    pending,
    kept,
    look,
    message,
    marked = null,
    locale,
    onPick,
    onAdd,
}: {
    addresses: CompanySavedAddressData[];
    /** The saved address the copy came from. */
    pickedId: string | null;
    /** The one being picked now, while its save is out. */
    pending: string | null;
    kept: string | null;
    look: Look;
    message: string | null;
    /** The last decision's mark, while the address it saw is still the one kept. */
    marked?: string | null;
    locale: 'ar' | 'en';
    onPick: (addressId: string) => void;
    /** Leaves for the Addresses tab in its turn; without it, the link goes at once. */
    onAdd?: () => void;
}) {
    const t = useTranslator();
    const link = useLink();
    const stateId = 'company-address-state';
    const busy = pending !== null;
    const picked = (address: CompanySavedAddressData): boolean =>
        pending !== null ? address.id === pending : address.id === pickedId && kept !== null && normal(address.formatted) === kept;
    const value = addresses.find(picked)?.id ?? '';
    const changed = pending === null && addresses.some((address) => address.id === pickedId && !picked(address));
    const addUrl = link('storefront.account', { tab: 'addresses', return: 'b2b.company' });
    const storeOf = (address: CompanySavedAddressData) => (locale === 'ar' ? address.storeNameAr : address.storeNameEn);
    const problem = (mustFix(look) ? message : null) ?? marked;
    // "Saved" only beside an address picked, seen and read out alike: beside an empty list, or one
    // that no longer holds the address kept, it would name nothing (amendment 26(c)).
    const shown: Look = value === '' && look === 'saved' ? 'idle' : look;

    // A link goes at once; inside the form a button leaves in its turn, after the saves.
    const add =
        onAdd === undefined ? (
            <Button asChild variant="outline" size="sm">
                <Link href={addUrl} data-test="add-address">
                    {t('b2b::company.address_add')}
                </Link>
            </Button>
        ) : (
            <Button type="button" variant="outline" size="sm" data-test="add-address" onClick={onAdd}>
                {t('b2b::company.address_add')}
            </Button>
        );

    return (
        <FieldSet className="gap-3" data-test="address-picker" aria-describedby={`company-address-hint ${stateId}`} data-invalid={problem !== null || undefined}>
            <FieldLegend variant="label" className="text-label-14 text-ink">
                {t('b2b::company.field.address')}
            </FieldLegend>
            <FieldDescription id="company-address-hint" className="text-copy-13 text-ink-muted">
                {t('b2b::company.address_pick')}
            </FieldDescription>

            {kept !== null ? (
                <Description columns={1} items={[{ title: t('b2b::company.address_kept'), content: <span dir="auto" className="whitespace-pre-line">{kept}</span>, 'data-test': 'address-kept' }]} />
            ) : null}

            {changed ? (
                <Note size="small" data-test="address-changed">
                    {t('b2b::company.address_changed')}
                </Note>
            ) : null}

            {addresses.length === 0 ? (
                <Empty className="border border-dashed border-line-strong p-6" data-test="address-none">
                    <EmptyHeader>
                        <EmptyTitle className="text-heading-16 text-ink">{t('b2b::company.address_none_title')}</EmptyTitle>
                        <EmptyDescription className="text-copy-14 text-ink-muted">{t('b2b::company.address_none')}</EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>{add}</EmptyContent>
                </Empty>
            ) : addresses.length > TILES ? (
                <>
                    <Suspense fallback={<Spinner className="text-ink-muted" aria-label={t('ui.loading')} />}>
                        <SearchCombobox
                            id="company-address-search"
                            label={t('b2b::company.address_saved_list')}
                            options={addresses.map((address) => ({
                                value: address.id,
                                label: `${address.label} · ${storeOf(address)}`,
                                description: address.isComplete ? address.formatted : t('b2b::company.address_incomplete'),
                                disabled: !address.isComplete || busy,
                            }))}
                            value={value}
                            // The address already picked is not saved again.
                            onChange={(next) => (next === value ? undefined : onPick(next))}
                            words={{ search: t('b2b::company.address_search'), none: (query) => t('b2b::company.address_search_none', { query }) }}
                            data-test="address-search"
                        />
                    </Suspense>
                    <div>{add}</div>
                </>
            ) : (
                <>
                    <RadioGroup name="company-address" value={value} onValueChange={onPick} className="gap-2" disabled={busy}>
                        {addresses.map((address) => {
                            const id = `saved-address-${address.id}`;

                            return (
                                // The whole tile is the target and shows the keyboard's place
                                // (Geist's Choicebox, shadcn's field-choice-card).
                                <FieldLabel
                                    key={address.id}
                                    htmlFor={id}
                                    className={cn(
                                        'has-[[data-state=checked]]:border-brand has-[[data-state=checked]]:bg-brand-soft/40 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                        !address.isComplete && 'cursor-not-allowed opacity-70',
                                    )}
                                >
                                    <Field orientation="horizontal">
                                        <FieldContent>
                                            <FieldTitle id={`${id}-title`} className="text-label-14 text-ink">
                                                <span dir="auto">{address.label}</span>
                                            </FieldTitle>
                                            <FieldDescription id={`${id}-text`} className="text-copy-13 text-ink-muted">
                                                <span dir="auto" className="block whitespace-pre-line">
                                                    {address.formatted}
                                                </span>
                                                <span className="block text-label-12 text-ink-subtle">{storeOf(address)}</span>
                                                {address.isComplete ? null : <span className="block text-warn">{t('b2b::company.address_incomplete')}</span>}
                                            </FieldDescription>
                                        </FieldContent>
                                        <RadioGroupItem
                                            value={address.id}
                                            id={id}
                                            disabled={!address.isComplete || busy}
                                            aria-labelledby={`${id}-title`}
                                            aria-describedby={`${id}-text`}
                                            className="border-ink-subtle"
                                            data-test={`pick-address-${address.id}`}
                                        />
                                    </Field>
                                </FieldLabel>
                            );
                        })}
                    </RadioGroup>

                    <div>{add}</div>
                </>
            )}

            {/* "Saving…" and "✓ Saved" seen, as every field of the form shows them (amendment 22(a)). */}
            <SaveBeside look={problem === null ? shown : 'idle'} />
            <FieldState id={stateId} look={shown} rule={null} problem={problem} />
        </FieldSet>
    );
}
