import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button, EmptyState, Fieldset, Input, Select } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { CurrenciesPage, CurrencyRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E3 - the currencies (frontend.md §3.5), in Geist's parts (1.10).
|
| Created and edited here, by a Super Admin alone [DECIDED 2026-09-19]: both permissions are
| reserved, so no role can carry them and nobody else reaches this screen at all.
|
| Two things on this screen are not ordinary fields.
|
| The **sign** is drawn as the shop will draw it, in the shop's own font, next to a sample amount.
| A sign the font cannot draw appears as an empty box here rather than in a customer's basket
| (platform.md §5.1) - which is the whole reason it is shown before it is saved.
|
| The **decimal places** are settled the moment any store charges in the currency (platform.md
| §1.2). Changing the number afterwards would reinterpret every amount ever written in it: 1000 is
| ten riyals at two places and a thousand at none. So the field is shown as settled, with the
| reason in its helper text, rather than offered and refused.
|
| A new currency is Geist's Fieldset, its one button in the footer; an existing one is a card whose
| form opens under its header and saves from a footer of the same shape.
*/

type Props = CurrenciesPage;

export default function Index({ currencies, exponents }: Props) {
    const t = useTranslator();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <AdminLayout
            title={t('platform::admin_currencies.title')}
            subtitle={t('platform::admin_currencies.subtitle')}
            action={
                // The page's main action while it is closed; once open it only closes, so it steps
                // back to a supporting button.
                <Button
                    type={adding ? 'secondary' : 'default'}
                    data-test="add-currency"
                    onClick={() => setAdding((open) => !open)}
                >
                    {t(adding ? 'platform::admin_currencies.cancel' : 'platform::admin_currencies.add')}
                </Button>
            }
        >
            <div className="grid gap-4">
                <FormError />

                {adding ? <AddForm exponents={exponents} onDone={() => setAdding(false)} /> : null}

                {currencies.length === 0 ? (
                    <EmptyState
                        title={t('platform::admin_currencies.none_title')}
                        description={t('platform::admin_currencies.none')}
                    />
                ) : (
                    currencies.map((currency) => (
                        <Card
                            key={currency.code}
                            currency={currency}
                            exponents={exponents}
                            open={editing === currency.code}
                            onOpen={() => setEditing(editing === currency.code ? null : currency.code)}
                            onDone={() => setEditing(null)}
                        />
                    ))
                )}
            </div>
        </AdminLayout>
    );
}

/** The sign as a price will show it, in the shop's own font. */
function SignPreview({ sign, abbreviation }: { sign: string; abbreviation: string }) {
    const t = useTranslator();
    const shown = sign.trim() === '' ? abbreviation : sign;

    return (
        <div className="flex items-center gap-3 rounded-[var(--tw-radius)] border border-line bg-surface-sunken px-3 py-2">
            <span className="text-label-13 text-ink-muted">{t('platform::admin_currencies.sign_preview')}</span>
            <span className="tw-figure text-label-18 text-ink" dir="ltr">
                1,234.50 <span className="text-ink">{shown}</span>
            </span>
        </div>
    );
}

type CardProps = {
    currency: CurrencyRow;
    exponents: number[];
    open: boolean;
    onOpen: () => void;
    onDone: () => void;
};

function Card({ currency, exponents, open, onOpen, onDone }: CardProps) {
    const t = useTranslator();

    const form = useForm({
        name_ar: currency.nameAr,
        name_en: currency.nameEn,
        abbreviation_ar: currency.abbreviationAr,
        abbreviation_en: currency.abbreviationEn,
        sign: currency.sign ?? '',
        exponent: String(currency.exponent),
    });

    function save() {
        // A settled exponent is not sent at all: the screen never asks for a change it knows is
        // refused, and the handler is left to answer for the fields that really were offered.
        form.transform((data) => {
            const { exponent, ...rest } = data;

            return currency.exponentLocked ? rest : { ...rest, exponent };
        });

        form.post(`/admin/currencies/${currency.code}`, { onSuccess: onDone });
    }

    return (
        <section className="material-base overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div className="grid gap-0.5">
                    <h2 className="text-heading-16 text-ink">
                        <span className="tw-figure">{currency.code}</span> · {currency.name}
                    </h2>
                    <p className="text-copy-13 text-ink-muted">
                        <span className="tw-figure">{currency.sign ?? currency.abbreviationEn}</span> ·{' '}
                        {t('platform::admin_currencies.exponent')}:{' '}
                        <span className="tw-figure">{currency.exponent}</span> ·{' '}
                        {currency.storeCount === 0
                            ? t('platform::admin_currencies.in_use_none')
                            : t('platform::admin_currencies.in_use', { count: currency.storeCount })}
                    </p>
                </div>

                <Button type="secondary" data-test={`edit-${currency.code}`} onClick={onOpen}>
                    {t(open ? 'platform::admin_currencies.cancel' : 'platform::admin_currencies.edit')}
                </Button>
            </header>

            {open ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                    className="border-t border-line"
                >
                    <div className="grid gap-4 p-5 sm:grid-cols-2">
                        <Names form={form} prefix={currency.code} />

                        <Input
                            id={`${currency.code}-sign`}
                            label={t('platform::admin_currencies.sign')}
                            helper={t('platform::admin_currencies.sign_hint')}
                            error={form.errors.sign}
                            value={form.data.sign}
                            onChange={(event) => form.setData('sign', event.target.value)}
                        />

                        <div className="sm:col-span-2">
                            <SignPreview sign={form.data.sign} abbreviation={form.data.abbreviation_en} />
                        </div>

                        <Select
                            id={`${currency.code}-exponent`}
                            label={t('platform::admin_currencies.exponent')}
                            helper={
                                currency.exponentLocked
                                    ? t('platform::admin_currencies.exponent_locked', { count: currency.storeCount })
                                    : t('platform::admin_currencies.exponent_hint')
                            }
                            error={form.errors.exponent}
                            dir="ltr"
                            disabled={currency.exponentLocked}
                            value={form.data.exponent}
                            onChange={(event) => form.setData('exponent', event.target.value)}
                        >
                            {exponents.map((places) => (
                                <option key={places} value={String(places)}>
                                    {places}
                                </option>
                            ))}
                        </Select>
                    </div>

                    <div className="flex justify-end border-t border-line bg-surface-sunken px-5 py-3">
                        <Button typeName="submit" loading={form.processing}>
                            {t('platform::admin_currencies.save')}
                        </Button>
                    </div>
                </form>
            ) : null}
        </section>
    );
}

function AddForm({ exponents, onDone }: { exponents: number[]; onDone: () => void }) {
    const t = useTranslator();

    const form = useForm({
        code: '',
        name_ar: '',
        name_en: '',
        abbreviation_ar: '',
        abbreviation_en: '',
        sign: '',
        exponent: '2',
    });

    return (
        <Fieldset
            as="form"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/admin/currencies', { onSuccess: onDone });
            }}
            title={t('platform::admin_currencies.add')}
            footerAction={
                <Button typeName="submit" data-test="create-currency" loading={form.processing}>
                    {t('platform::admin_currencies.create')}
                </Button>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Input
                    id="new-code"
                    label={t('platform::admin_currencies.code')}
                    helper={t('platform::admin_currencies.code_hint')}
                    error={form.errors.code}
                    required
                    dir="ltr"
                    maxLength={3}
                    value={form.data.code}
                    // Upper case as it is typed: a currency code has no other form.
                    onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                />

                <Select
                    id="new-exponent"
                    label={t('platform::admin_currencies.exponent')}
                    helper={t('platform::admin_currencies.exponent_hint')}
                    error={form.errors.exponent}
                    dir="ltr"
                    value={form.data.exponent}
                    onChange={(event) => form.setData('exponent', toLatinDigits(event.target.value))}
                >
                    {exponents.map((places) => (
                        <option key={places} value={String(places)}>
                            {places}
                        </option>
                    ))}
                </Select>

                <Names form={form} prefix="new" />

                <Input
                    id="new-sign"
                    label={t('platform::admin_currencies.sign')}
                    helper={t('platform::admin_currencies.sign_hint')}
                    error={form.errors.sign}
                    value={form.data.sign}
                    onChange={(event) => form.setData('sign', event.target.value)}
                />

                <div className="sm:col-span-2">
                    <SignPreview sign={form.data.sign} abbreviation={form.data.abbreviation_en} />
                </div>
            </div>
        </Fieldset>
    );
}

/*
| The four name fields, which are the same whether a currency is being made or changed.
|
| Typed loosely on purpose: both forms carry these four keys and differ in the rest, and a shared
| piece that insisted on knowing every field of both would have to be changed whenever either form
| gained one.
*/
type NamesForm = {
    data: Record<string, string>;
    errors: Partial<Record<string, string>>;
    setData: (field: never, value: never) => void;
};

function Names({ form, prefix }: { form: NamesForm; prefix: string }) {
    const t = useTranslator();

    const fields = [
        { key: 'name_ar', label: 'platform::admin_currencies.name_ar', lang: 'ar', hint: undefined },
        { key: 'name_en', label: 'platform::admin_currencies.name_en', lang: 'en', hint: undefined },
        {
            key: 'abbreviation_ar',
            label: 'platform::admin_currencies.abbreviation_ar',
            lang: 'ar',
            hint: 'platform::admin_currencies.abbreviation_hint',
        },
        {
            key: 'abbreviation_en',
            label: 'platform::admin_currencies.abbreviation_en',
            lang: 'en',
            hint: 'platform::admin_currencies.abbreviation_hint',
        },
    ] as const;

    return (
        <>
            {fields.map((field) => (
                <Input
                    key={field.key}
                    id={`${prefix}-${field.key}`}
                    label={t(field.label)}
                    helper={field.hint === undefined ? undefined : t(field.hint)}
                    error={form.errors[field.key]}
                    required
                    lang={field.lang}
                    dir={field.lang === 'en' ? 'ltr' : undefined}
                    value={form.data[field.key] ?? ''}
                    onChange={(event) => form.setData(field.key as never, event.target.value as never)}
                />
            ))}
        </>
    );
}
