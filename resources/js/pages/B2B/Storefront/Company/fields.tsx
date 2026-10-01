import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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

/** The border a control takes for how it stands. */
export function border(look: Look): string {
    switch (look) {
        case 'invalid':
            return 'border-warn';
        case 'refused':
            return 'border-bad';
        case 'saved':
            return 'border-good';
        default:
            return 'border-line-strong';
    }
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
            className={['mt-1 min-h-4 text-xs', COLOUR[look]].join(' ')}
        >
            {text ?? ''}
        </p>
    );
}

/**
 * The account's saved addresses to pick one from (amendment 16(f)): any store's, each as its store's
 * format writes it. One the format no longer accepts is shown and cannot be picked. With none saved,
 * the section says so; **Add an address** opens the account's Addresses tab, which brings the person
 * back here once one is saved (access.md amendment 51) — in the form, after the saves still waiting
 * (`onAdd`, amendment 17(i)).
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

    return (
        <fieldset className="grid gap-3" data-test="address-picker" aria-describedby={stateId}>
            <legend className="text-sm font-medium text-ink">{t('b2b::company.field.address')}</legend>
            <p className="text-xs text-ink-muted">{t('b2b::company.address_pick')}</p>

            {kept !== null ? (
                <div className={['grid gap-0.5 rounded-md border p-3', border(look)].join(' ')} data-test="address-kept">
                    <span className="text-xs text-ink-muted">{t('b2b::company.address_kept')}</span>
                    <span dir="auto" className="text-sm whitespace-pre-line text-ink">
                        {kept}
                    </span>
                </div>
            ) : null}

            {changed ? (
                <p className="text-xs text-ink" data-test="address-changed">
                    {t('b2b::company.address_changed')}
                </p>
            ) : null}

            {addresses.length === 0 ? (
                <p className="text-sm text-ink" data-test="address-none">
                    {t('b2b::company.address_none')}
                </p>
            ) : (
                <ul className="grid gap-2">
                    {addresses.map((address, index) => {
                        const store = locale === 'ar' ? address.storeNameAr : address.storeNameEn;
                        const before = addresses[index - 1];
                        const firstOfStore = before === undefined || (locale === 'ar' ? before.storeNameAr : before.storeNameEn) !== store;
                        const id = `saved-address-${address.id}`;

                        return (
                            <li key={address.id} className="grid gap-1">
                                {firstOfStore ? <p className="text-xs font-semibold text-ink-muted">{store}</p> : null}
                                <label
                                    htmlFor={id}
                                    className={[
                                        'flex gap-3 rounded-md border p-3',
                                        address.id === pickedId ? 'border-brand bg-brand-soft/20' : 'border-line',
                                        address.isComplete && !disabled ? 'cursor-pointer' : 'opacity-70',
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
                                        className="mt-1"
                                    />
                                    <span className="grid gap-0.5">
                                        <span dir="auto" className="text-sm font-medium text-ink">
                                            {address.label}
                                        </span>
                                        <span dir="auto" className="text-sm whitespace-pre-line text-ink-muted">
                                            {address.formatted}
                                        </span>
                                        {address.isComplete ? null : <span className="text-xs text-warn">{t('b2b::company.address_incomplete')}</span>}
                                    </span>
                                </label>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div>
                {onAdd === undefined ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={addUrl} data-test="add-address">
                            {t('b2b::company.address_add')}
                        </Link>
                    </Button>
                ) : (
                    <Button type="button" variant="outline" size="sm" data-test="add-address" onClick={onAdd}>
                        {t('b2b::company.address_add')}
                    </Button>
                )}
            </div>

            <FieldState id={stateId} look={look} message={message} />
        </fieldset>
    );
}
