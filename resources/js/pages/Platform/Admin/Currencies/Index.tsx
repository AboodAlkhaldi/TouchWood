import { useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { SelectField, TextField } from '@/components/Fields';
import { FormError, useFreshRefusal } from '@/components/FormError';
import { Description } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NativeSelectOption } from '@/components/ui/native-select';
import type { Rules } from '@/lib/checks';
import { figure, toLatinDigits } from '@/lib/digits';
import { useList } from '@/lib/list';
import { useTranslator } from '@/lib/t';
import { type Box, type Checks, useChecks } from '@/lib/use-checks';
import { useFocusBack } from '@/lib/use-focus-back';
import type { CurrenciesPage, CurrencyRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| E3 - the currencies (frontend.md §3.5), each one of shadcn's Cards in Geist's Fieldset form
| (§1.11).
|
| Created and edited here, by a Super Admin alone [DECIDED 2026-09-19]: both permissions are
| reserved, so no role can carry them and nobody else reaches this screen at all.
|
| Two things on this screen are not ordinary fields.
|
| The **sign** is drawn as the shop will draw it, in the shop's own font, next to a sample amount -
| Geist's Description, "Preview" and the price. A sign the font cannot draw appears as an empty box
| here rather than in a customer's basket (platform.md §5.1), which is the whole reason it is shown
| before it is saved.
|
| The **decimal places** are settled the moment any store charges in the currency (platform.md
| §1.2). Changing the number afterwards would reinterpret every amount ever written in it: 1000 is
| ten riyals at two places and a thousand at none. So the field is shown as settled, with the
| reason in its helper text, rather than offered and refused.
|
| A new currency is a Card opened by the page's Add button; an existing one is a Card whose form
| opens in shadcn's Collapsible under its header. Either form ends with **Cancel and its main button
| side by side** in its footer, and the button that opened it steps out of the way while it is open;
| Delete, for a currency no store uses, sits apart on the footer's start side (the owner,
| 2026-10-06: the buttons' places).
*/

type Props = CurrenciesPage;

export default function Index({ currencies, exponents }: Props) {
    const t = useTranslator();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);
    const addButton = useRef<HTMLButtonElement>(null);
    useFocusBack(adding, addButton);

    return (
        <AdminLayout
            title={t('platform::admin_currencies.title')}
            subtitle={t('platform::admin_currencies.subtitle')}
            action={
                // The page's main action, while its form is closed. Once open, the form ends with
                // its own Cancel and Create side by side, where the eye already is (the owner,
                // 2026-10-06: the buttons' places; a form's actions close it, Geist's Card footer).
                adding ? undefined : (
                    <Button ref={addButton} type="button" data-test="add-currency" onClick={() => setAdding(true)}>
                        {t('platform::admin_currencies.add')}
                    </Button>
                )
            }
        >
            <div className="grid gap-4">
                <FormError />

                {adding ? <AddForm exponents={exponents} onDone={() => setAdding(false)} /> : null}

                {currencies.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('platform::admin_currencies.none_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('platform::admin_currencies.none')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    currencies.map((currency) => (
                        <CurrencyCard
                            key={currency.code}
                            currency={currency}
                            exponents={exponents}
                            open={editing === currency.code}
                            onOpenChange={(open) => setEditing(open ? currency.code : null)}
                        />
                    ))
                )}
            </div>
        </AdminLayout>
    );
}

/** The sign as a price will show it, in the shop's own font (Geist's Description: a key, one value). */
function SignPreview({ sign, abbreviation }: { sign: string; abbreviation: string }) {
    const t = useTranslator();
    const shown = sign.trim() === '' ? abbreviation : sign;

    return (
        <Description
            columns={1}
            items={[
                {
                    title: t('platform::admin_currencies.sign_preview'),
                    content: (
                        <span className="tw-figure text-label-18 text-ink">
                            <bdi dir="ltr">1,234.50 {shown}</bdi>
                        </span>
                    ),
                    'data-test': 'sign-preview',
                },
            ]}
        />
    );
}

type CardProps = {
    currency: CurrencyRow;
    exponents: number[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function CurrencyCard({ currency, exponents, open, onOpenChange }: CardProps) {
    const t = useTranslator();
    const list = useList();
    const { locale } = usePage<SharedProps>().props;
    const [deleting, setDeleting] = useState(false);
    const deleteButton = useRef<HTMLButtonElement>(null);
    const editButton = useRef<HTMLButtonElement>(null);
    const editForm = useRef<HTMLFormElement>(null);
    useFocusBack(open, editButton, editForm);
    // Only the delete's own refusal, never an older one from saving (useFreshRefusal).
    const deleteRefusal = useFreshRefusal(deleting);
    const remove = useForm({});
    // The stores are named, not counted (platform.md §9.7): ":count stores" once read "1 stores".
    const storeNames = list(currency.stores.map((store) => (store.isActive ? store.name : t('platform::admin_currencies.store_off', { name: store.name }))));

    const form = useForm({
        name_ar: currency.nameAr,
        name_en: currency.nameEn,
        abbreviation_ar: currency.abbreviationAr,
        abbreviation_en: currency.abbreviationEn,
        sign: currency.sign ?? '',
        exponent: String(currency.exponent),
    });
    // Each box as typed, with the currency's rules (CURRENCY, below); the places are picked from a list.
    const checks = useChecks([
        ...nameBoxes(currency.code, form.data, t),
        { id: `${currency.code}-sign`, label: t('platform::admin_currencies.sign'), value: form.data.sign, rules: CURRENCY.sign },
    ]);

    function save() {
        // A settled exponent is not sent at all: the screen never asks for a change it knows is
        // refused, and the handler is left to answer for the fields that really were offered.
        form.transform((data) => {
            const { exponent, ...rest } = data;

            return currency.exponentLocked ? rest : { ...rest, exponent };
        });

        form.post(`/admin/currencies/${currency.code}`, { onSuccess: () => onOpenChange(false) });
    }

    return (
        <Collapsible open={open} onOpenChange={onOpenChange} asChild>
            <Card className="material-base gap-0 overflow-hidden border-0 py-0" data-test={`currency-${currency.code}`}>
                <CardHeader className="px-5 py-4">
                    <CardTitle>
                        <h2 className="text-heading-16 text-ink">
                            <span className="tw-figure">{currency.code}</span> · {currency.name}
                        </h2>
                    </CardTitle>
                    <CardDescription className="text-copy-13 text-ink-muted">
                        <bdi dir="ltr" className="tw-figure">
                            {currency.sign ?? currency.abbreviationEn}
                        </bdi>{' '}
                        · {t('platform::admin_currencies.exponent')}: {figure(locale, currency.exponent)} ·{' '}
                        <span data-test={`stores-${currency.code}`}>
                            {currency.stores.length === 0 ? t('platform::admin_currencies.in_use_none') : t('platform::admin_currencies.in_use', { stores: storeNames })}
                        </span>
                    </CardDescription>
                    {/* Edit while closed; once open, the form's own footer closes it. */}
                    {open ? null : (
                        <CardAction>
                            <CollapsibleTrigger asChild>
                                <Button ref={editButton} type="button" variant="outline" data-test={`edit-${currency.code}`}>
                                    {t('platform::admin_currencies.edit')}
                                </Button>
                            </CollapsibleTrigger>
                        </CardAction>
                    )}
                </CardHeader>

                <CollapsibleContent>
                    <form
                        ref={editForm}
                        onSubmit={(event) => {
                            event.preventDefault();
                            checks.submit(save);
                        }}
                        className="border-t border-line"
                    >
                        <CardContent className="grid gap-5 p-5 sm:grid-cols-2">
                            <Names form={form} prefix={currency.code} checks={checks} autoFocus />

                            <TextField
                                id={`${currency.code}-sign`}
                                label={t('platform::admin_currencies.sign')}
                                helper={t('platform::admin_currencies.sign_hint')}
                                check={checks.box(`${currency.code}-sign`, form.errors.sign)}
                                value={form.data.sign}
                                onChange={(event) => form.setData('sign', event.target.value)}
                            />

                            <SelectField
                                id={`${currency.code}-exponent`}
                                label={t('platform::admin_currencies.exponent')}
                                helper={
                                    currency.exponentLocked
                                        ? t('platform::admin_currencies.exponent_locked', { stores: storeNames })
                                        : t('platform::admin_currencies.exponent_hint', { two: figure(locale, 2), zero: figure(locale, 0) })
                                }
                                error={form.errors.exponent}
                                disabled={currency.exponentLocked}
                                value={form.data.exponent}
                                onChange={(event) => form.setData('exponent', event.target.value)}
                            >
                                {exponents.map((places) => (
                                    <NativeSelectOption key={places} value={String(places)}>
                                        {figure(locale, places)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>

                            <div className="sm:col-span-2">
                                <SignPreview sign={form.data.sign} abbreviation={form.data.abbreviation_en} />
                            </div>
                        </CardContent>

                        <CardFooter className="flex-wrap justify-between gap-2 border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                            {/* Destructive, so apart from Save, on the start side; only for a currency no
                                store uses (platform.md §9.7) - one in use says so on its line. */}
                            {currency.deletable ? (
                                <Button
                                    ref={deleteButton}
                                    type="button"
                                    variant="outline"
                                    className="text-bad"
                                    data-test={`delete-${currency.code}`}
                                    onClick={() => setDeleting(true)}
                                >
                                    {`${t('platform::admin_currencies.delete')}…`}
                                </Button>
                            ) : (
                                <span />
                            )}
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" disabled={form.processing} data-test={`cancel-${currency.code}`} onClick={() => onOpenChange(false)}>
                                    {t('platform::admin_currencies.cancel')}
                                </Button>
                                <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test={`save-${currency.code}`}>
                                    {t('platform::admin_currencies.save')}
                                </ActionButton>
                            </div>
                        </CardFooter>
                    </form>
                </CollapsibleContent>

                {/* Geist's Destructive Action Modal: the code typed before it goes (platform.md §9.7). */}
                <DestructiveActionDialog
                    open={deleting}
                    onOpenChange={setDeleting}
                    title={t('platform::admin_currencies.delete_title')}
                    confirmLabel={t('platform::admin_currencies.delete_confirm')}
                    description={t('platform::admin_currencies.delete_body', { code: currency.code, name: currency.name })}
                    irreversibleDescription={t('platform::admin_currencies.delete_irreversible', { code: currency.code })}
                    verificationPhrase={currency.code}
                    verificationLabel={t('platform::admin_currencies.verification_label')}
                    loading={remove.processing}
                    error={deleteRefusal}
                    onConfirm={() => remove.post(`/admin/currencies/${currency.code}/delete`, { onSuccess: () => setDeleting(false) })}
                    returnFocusTo={deleteButton}
                />
            </Card>
        </Collapsible>
    );
}

function AddForm({ exponents, onDone }: { exponents: number[]; onDone: () => void }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;

    const form = useForm({
        code: '',
        name_ar: '',
        name_en: '',
        abbreviation_ar: '',
        abbreviation_en: '',
        sign: '',
        exponent: '2',
    });
    // Each box as typed, with the currency's rules (CURRENCY, below); the places are picked from a list.
    const checks = useChecks([
        { id: 'new-code', label: t('platform::admin_currencies.code'), value: form.data.code, rules: CURRENCY.code },
        ...nameBoxes('new', form.data, t),
        { id: 'new-sign', label: t('platform::admin_currencies.sign'), value: form.data.sign, rules: CURRENCY.sign },
    ]);

    return (
        <Card className="material-base gap-0 border-0 py-0">
            <form
                id="add-currency-form"
                aria-labelledby="add-currency-title"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => form.post('/admin/currencies', { onSuccess: onDone }));
                }}
            >
                <CardHeader className="px-5 pt-5 pb-4">
                    <CardTitle>
                        <h2 id="add-currency-title" className="text-heading-16 text-ink">
                            {t('platform::admin_currencies.add')}
                        </h2>
                    </CardTitle>
                </CardHeader>

                <CardContent className="grid gap-5 px-5 pb-5 sm:grid-cols-2">
                    <TextField
                        id="new-code"
                        label={t('platform::admin_currencies.code')}
                        helper={t('platform::admin_currencies.code_hint')}
                        check={checks.box('new-code', form.errors.code)}
                        required
                        // The form appears because Add Currency was pressed: its first field takes the focus.
                        autoFocus
                        dir="ltr"
                        inputClassName="tw-figure"
                        value={form.data.code}
                        // Upper case as it is typed: a currency code has no other form.
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                    />

                    <SelectField
                        id="new-exponent"
                        label={t('platform::admin_currencies.exponent')}
                        helper={t('platform::admin_currencies.exponent_hint', { two: figure(locale, 2), zero: figure(locale, 0) })}
                        error={form.errors.exponent}
                        value={form.data.exponent}
                        onChange={(event) => form.setData('exponent', toLatinDigits(event.target.value))}
                    >
                        {exponents.map((places) => (
                            <NativeSelectOption key={places} value={String(places)}>
                                {figure(locale, places)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>

                    <Names form={form} prefix="new" checks={checks} />

                    <TextField
                        id="new-sign"
                        label={t('platform::admin_currencies.sign')}
                        helper={t('platform::admin_currencies.sign_hint')}
                        check={checks.box('new-sign', form.errors.sign)}
                        value={form.data.sign}
                        onChange={(event) => form.setData('sign', event.target.value)}
                    />

                    <div className="sm:col-span-2">
                        <SignPreview sign={form.data.sign} abbreviation={form.data.abbreviation_en} />
                    </div>
                </CardContent>

                <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                    <Button type="button" variant="outline" disabled={form.processing} data-test="cancel-add-currency" onClick={onDone}>
                        {t('platform::admin_currencies.cancel')}
                    </Button>
                    <ActionButton type="submit" data-test="create-currency" loading={form.processing} disabledReason={checks.reason}>
                        {t('platform::admin_currencies.create')}
                    </ActionButton>
                </CardFooter>
            </form>
        </Card>
    );
}

/*
| The four name fields, which are the same whether a currency is being made or changed.
|
| Typed loosely on purpose: both forms carry these four keys and differ in the rest, and a shared
| piece that insisted on knowing every field of both would have to be changed whenever either form
| gained one. Each label is written out, so the words check (TranslationKeysTest) reads every key.
*/
type NamesForm = {
    data: Record<string, string>;
    errors: Partial<Record<string, string>>;
    setData: (field: never, value: never) => void;
};

/*
| A currency's rules as each box is typed (frontend.md §1.7), the domain's own: a code of three
| letters A to Z (CurrencyCode, ISO 4217; upper-cased as it is typed), the names and abbreviations
| required in both languages with no maximum (TranslatedText), and a sign of one character or none
| (Currency::assertSign). Platform has no Form Request: a refusal still comes back as the form's.
*/
const CURRENCY = {
    code: { required: true, letters: true, length: { min: 3, max: 3 } },
    name: { required: true },
    sign: { length: { max: 1 } },
} satisfies Record<string, Rules>;

/** The four name boxes' checks, in the order Names shows them. */
function nameBoxes(prefix: string, data: Record<string, string>, t: (key: string) => string): Box[] {
    const box = (key: string, label: string): Box => ({ id: `${prefix}-${key}`, label, value: data[key] ?? '', rules: CURRENCY.name });

    return [
        box('name_ar', t('platform::admin_currencies.name_ar')),
        box('name_en', t('platform::admin_currencies.name_en')),
        box('abbreviation_ar', t('platform::admin_currencies.abbreviation_ar')),
        box('abbreviation_en', t('platform::admin_currencies.abbreviation_en')),
    ];
}

function Names({
    form,
    prefix,
    checks,
    autoFocus = false,
}: {
    form: NamesForm;
    prefix: string;
    /** The form's checks, which hold these four boxes (nameBoxes). */
    checks: Checks;
    /** The first name takes focus: the edit form opens on it. */
    autoFocus?: boolean;
}) {
    const t = useTranslator();
    const field = (key: string, label: string, lang: 'ar' | 'en', hint?: string) => (
        <TextField
            key={key}
            id={`${prefix}-${key}`}
            label={label}
            helper={hint}
            check={checks.box(`${prefix}-${key}`, form.errors[key])}
            required
            autoFocus={autoFocus && key === 'name_ar'}
            lang={lang}
            dir={lang === 'en' ? 'ltr' : 'rtl'}
            value={form.data[key] ?? ''}
            onChange={(event) => form.setData(key as never, event.target.value as never)}
        />
    );

    return (
        <>
            {field('name_ar', t('platform::admin_currencies.name_ar'), 'ar')}
            {field('name_en', t('platform::admin_currencies.name_en'), 'en')}
            {field('abbreviation_ar', t('platform::admin_currencies.abbreviation_ar'), 'ar', t('platform::admin_currencies.abbreviation_hint'))}
            {field('abbreviation_en', t('platform::admin_currencies.abbreviation_en'), 'en', t('platform::admin_currencies.abbreviation_hint'))}
        </>
    );
}
