import { Button, ButtonLink, EmptyState, Note } from '@/components/geist';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyFieldRuleData,
    CompanySavedAddressData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';

/*
| How a field of the company page says where it stands (b2b.md §4.5, amendment 16(a)), and the
| address picker (amendment 16(f)).
|
| **Yellow** while what it holds is not valid — and then it is never sent; **"Saving…"** while its
| save is out; **green, "Saved"**, once the server holds it; **red**, with the reason, if the server
| refuses it all the same. The rules come from the server, the same numbers it checks, and both trim
| the same characters: JavaScript's `trim()` is the rule, and the server's CompanyText::trimmed
| removes exactly what it does (amendment 17(a)).
|
| Geist's Input has one error colour and no "saved" one, so these fields are built from Geist's own
| parts (frontend.md §1.8, 1.10): Geist's field box — its height, corners, type and one-pixel ring —
| with the ring in the colour of where the field stands, Geist's Label above, and the line below that
| says it in words. A saved address picked from a list is a tile of native radios, so the browser
| keeps their keyboard handling and their checked state.
*/

/** A value as the server keeps it: line breaks as one, and nothing at either end (17(a)). */
export function normal(value: string): string {
    return value.replace(/\r\n?/g, '\n').trim();
}

/** Where a field stands, as the person sees it. */
export type Look = 'idle' | 'unsaved' | 'saving' | 'saved' | 'invalid' | 'refused';

type Translate = (key: string, values?: Record<string, string | number>) => string;

/**
 * What is wrong with a value, in words — or null when the server would take it. Written as the
 * domain checks it: spaces at either end do not count, and a length is in characters, not bytes.
 */
export function check(value: string, rule: CompanyFieldRuleData | undefined, required: boolean, t: Translate): string | null {
    const text = normal(value);

    if (text === '') {
        return required ? t('b2b::company.check.required') : null;
    }

    if (rule === undefined) {
        return null;
    }

    if (rule.oneLine ? /\p{Cc}/u.test(text) : /[^\P{Cc}\n]/u.test(text)) {
        return t(rule.oneLine ? 'b2b::company.check.one_line' : 'b2b::company.check.lines');
    }

    const length = [...text].length;

    if (length < rule.min) {
        return t('b2b::company.check.min', { count: rule.min });
    }

    if (length > rule.max) {
        return t('b2b::company.check.max', { count: rule.max });
    }

    if (rule.characters !== null && !new RegExp(rule.characters, 'u').test(text)) {
        return t('b2b::company.check.characters');
    }

    return null;
}

/** The ring a box takes for how it stands: Geist's one pixel, in the colour of the field's state. */
const RING: Record<Look, string> = {
    idle: 'shadow-[0_0_0_1px_var(--tw-line-strong)]',
    unsaved: 'shadow-[0_0_0_1px_var(--tw-line-strong)]',
    saving: 'shadow-[0_0_0_1px_var(--tw-line-strong)]',
    saved: 'shadow-[0_0_0_1px_var(--tw-good)]',
    invalid: 'shadow-[0_0_0_1px_var(--tw-warn)]',
    refused: 'shadow-[0_0_0_1px_var(--tw-bad)]',
};

/** Geist's field box; focus rings it in brand, as Geist's Input does. */
const BOX =
    'w-full rounded-[var(--tw-radius)] bg-surface text-copy-14 text-ink outline-none transition-shadow placeholder:text-ink-subtle focus:shadow-[0_0_0_1px_var(--tw-brand)]';

const SHAPE = {
    line: 'h-9 px-3',
    lines: 'block resize-y px-3 py-2',
    choice: 'h-9 appearance-none pe-9 ps-3',
} as const;

/** A control of the company form in Geist's field look, ringed in the colour of where it stands. */
export function control(look: Look, shape: keyof typeof SHAPE): string {
    return [BOX, SHAPE[shape], RING[look]].join(' ');
}

/** The ring alone, for a box that shows what a field keeps rather than taking input. */
export function ring(look: Look): string {
    return RING[look];
}

const COLOUR: Record<Look, string> = {
    idle: 'text-ink-muted',
    unsaved: 'text-ink-muted',
    saving: 'text-ink-muted',
    saved: 'text-good',
    invalid: 'text-warn',
    refused: 'text-bad',
};

/**
 * The line under a field, always there, so a word appearing in it never moves what is below.
 * `message` is the reason for yellow or red.
 */
export function FieldState({ id, look, message }: { id: string; look: Look; message: string | null }) {
    const t = useTranslator();
    const text = look === 'saving' ? t('b2b::company.saving') : look === 'saved' ? t('b2b::company.saved') : look === 'invalid' || look === 'refused' ? message : null;

    // A refusal is said at once; anything else waits its turn (an alert is already assertive).
    return (
        <p
            id={id}
            role={look === 'refused' ? 'alert' : 'status'}
            aria-live={look === 'refused' ? undefined : 'polite'}
            data-test="field-state"
            data-look={look}
            className={['min-h-4.5 text-copy-13', COLOUR[look]].join(' ')}
        >
            {text ?? ''}
        </p>
    );
}

/**
 * The account's saved addresses to pick one from (amendment 16(f)): any store's, each as its store's
 * format writes it. One the format no longer accepts is shown and cannot be picked. With none saved,
 * the section is Geist's Empty State; **Add an address** opens the account's Addresses tab, which
 * brings the person back here once one is saved (access.md amendment 51) — in the form, after the
 * saves still waiting (`onAdd`, amendment 17(i)).
 *
 * What the company or the draft keeps is a copy — shown above the list, as it was when picked. The
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
    locale: 'ar' | 'en';
    onPick: (addressId: string) => void;
    /** Leaves for the Addresses tab in its turn; without it, the link goes at once. */
    onAdd?: () => void;
}) {
    const t = useTranslator();
    const link = useLink();
    const stateId = 'company-address-state';
    const disabled = pending !== null;
    const picked = (address: CompanySavedAddressData): boolean =>
        pending !== null ? address.id === pending : address.id === pickedId && kept !== null && normal(address.formatted) === kept;
    const changed = pending === null && addresses.some((address) => address.id === pickedId && !picked(address));
    const addUrl = link('storefront.account', { tab: 'addresses', return: 'b2b.company' });

    // A ButtonLink goes at once; inside the form a Button leaves in its turn, after the saves.
    const add =
        onAdd === undefined ? (
            <ButtonLink href={addUrl} type="secondary" size="small" data-test="add-address">
                {t('b2b::company.address_add')}
            </ButtonLink>
        ) : (
            <Button type="secondary" size="small" data-test="add-address" onClick={onAdd}>
                {t('b2b::company.address_add')}
            </Button>
        );

    return (
        <fieldset className="grid gap-3" data-test="address-picker" aria-describedby={stateId}>
            <legend className="mb-1.5 text-label-14 font-medium text-ink">{t('b2b::company.field.address')}</legend>
            <p className="text-copy-13 text-ink-muted">{t('b2b::company.address_pick')}</p>

            {kept !== null ? (
                <div className={['grid gap-0.5 rounded-[var(--tw-radius)] p-3', ring(look)].join(' ')} data-test="address-kept">
                    <span className="text-label-13 text-ink-muted">{t('b2b::company.address_kept')}</span>
                    <span dir="auto" className="text-copy-14 whitespace-pre-line text-ink">
                        {kept}
                    </span>
                </div>
            ) : null}

            {changed ? (
                <Note size="small" data-test="address-changed">
                    {t('b2b::company.address_changed')}
                </Note>
            ) : null}

            {addresses.length === 0 ? (
                <EmptyState
                    title={t('b2b::company.address_none_title')}
                    description={t('b2b::company.address_none')}
                    actions={add}
                    data-test="address-none"
                />
            ) : (
                <>
                    <ul className="grid gap-2">
                        {addresses.map((address, index) => {
                            const store = locale === 'ar' ? address.storeNameAr : address.storeNameEn;
                            const before = addresses[index - 1];
                            const firstOfStore = before === undefined || (locale === 'ar' ? before.storeNameAr : before.storeNameEn) !== store;
                            const id = `saved-address-${address.id}`;

                            return (
                                <li key={address.id} className="grid gap-1.5">
                                    {firstOfStore ? <p className="text-label-13 font-medium text-ink-muted">{store}</p> : null}
                                    <label
                                        htmlFor={id}
                                        className={[
                                            'flex gap-3 rounded-[var(--tw-radius)] p-3 transition-shadow',
                                            address.id === pickedId ? 'bg-brand-soft/20 shadow-[0_0_0_1px_var(--tw-brand)]' : 'shadow-[0_0_0_1px_var(--tw-line)]',
                                            address.isComplete && !disabled ? 'cursor-pointer' : 'cursor-not-allowed opacity-70',
                                        ].join(' ')}
                                    >
                                        <input
                                            id={id}
                                            type="radio"
                                            name="company-address"
                                            value={address.id}
                                            checked={picked(address)}
                                            disabled={!address.isComplete || disabled}
                                            data-test={`pick-address-${address.id}`}
                                            onChange={() => onPick(address.id)}
                                            className="mt-0.5 size-4 shrink-0 accent-brand"
                                        />
                                        <span className="grid gap-0.5">
                                            <span dir="auto" className="text-label-14 font-medium text-ink">
                                                {address.label}
                                            </span>
                                            <span dir="auto" className="text-copy-13 whitespace-pre-line text-ink-muted">
                                                {address.formatted}
                                            </span>
                                            {address.isComplete ? null : <span className="text-copy-13 text-warn">{t('b2b::company.address_incomplete')}</span>}
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>

                    <div>{add}</div>
                </>
            )}

            <FieldState id={stateId} look={look} message={message} />
        </fieldset>
    );
}
