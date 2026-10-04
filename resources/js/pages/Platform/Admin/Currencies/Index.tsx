import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Description } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NativeSelectOption } from '@/components/ui/native-select';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { CurrenciesPage, CurrencyRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

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
| A new currency is a Card whose one button is in its footer; an existing one is a Card whose form
| opens in shadcn's Collapsible under its header and saves from a footer of the same shape. The
| buttons that open a form say whether it is open (aria-expanded).
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
                // back to a supporting button. It sits in the header, away from the form it opens,
                // so it names that form itself (Geist's Collapse: aria-expanded, aria-controls).
                <Button
                    type="button"
                    variant={adding ? 'outline' : 'default'}
                    aria-expanded={adding}
                    // Only while the form is there: an id that points at nothing helps nobody.
                    aria-controls={adding ? 'add-currency-form' : undefined}
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
                        · {t('platform::admin_currencies.exponent')}: <span className="tw-figure">{currency.exponent}</span> ·{' '}
                        {currency.storeCount === 0 ? t('platform::admin_currencies.in_use_none') : t('platform::admin_currencies.in_use', { count: currency.storeCount })}
                    </CardDescription>
                    <CardAction>
                        <CollapsibleTrigger asChild>
                            <Button type="button" variant="outline" data-test={`edit-${currency.code}`}>
                                {t(open ? 'platform::admin_currencies.cancel' : 'platform::admin_currencies.edit')}
                            </Button>
                        </CollapsibleTrigger>
                    </CardAction>
                </CardHeader>

                <CollapsibleContent>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                        className="border-t border-line"
                    >
                        <CardContent className="grid gap-5 p-5 sm:grid-cols-2">
                            <Names form={form} prefix={currency.code} />

                            <TextField
                                id={`${currency.code}-sign`}
                                label={t('platform::admin_currencies.sign')}
                                helper={t('platform::admin_currencies.sign_hint')}
                                error={form.errors.sign}
                                value={form.data.sign}
                                onChange={(event) => form.setData('sign', event.target.value)}
                            />

                            <SelectField
                                id={`${currency.code}-exponent`}
                                label={t('platform::admin_currencies.exponent')}
                                helper={
                                    currency.exponentLocked
                                        ? t('platform::admin_currencies.exponent_locked', { count: currency.storeCount })
                                        : t('platform::admin_currencies.exponent_hint')
                                }
                                error={form.errors.exponent}
                                disabled={currency.exponentLocked}
                                value={form.data.exponent}
                                onChange={(event) => form.setData('exponent', event.target.value)}
                            >
                                {exponents.map((places) => (
                                    <NativeSelectOption key={places} value={String(places)}>
                                        {places}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>

                            <div className="sm:col-span-2">
                                <SignPreview sign={form.data.sign} abbreviation={form.data.abbreviation_en} />
                            </div>
                        </CardContent>

                        <CardFooter className="justify-end border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                            <ActionButton type="submit" loading={form.processing} data-test={`save-${currency.code}`}>
                                {t('platform::admin_currencies.save')}
                            </ActionButton>
                        </CardFooter>
                    </form>
                </CollapsibleContent>
            </Card>
        </Collapsible>
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
        <Card className="material-base gap-0 border-0 py-0">
            <form
                id="add-currency-form"
                aria-labelledby="add-currency-title"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/currencies', { onSuccess: onDone });
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
                        error={form.errors.code}
                        required
                        dir="ltr"
                        maxLength={3}
                        inputClassName="tw-figure"
                        value={form.data.code}
                        // Upper case as it is typed: a currency code has no other form.
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                    />

                    <SelectField
                        id="new-exponent"
                        label={t('platform::admin_currencies.exponent')}
                        helper={t('platform::admin_currencies.exponent_hint')}
                        error={form.errors.exponent}
                        value={form.data.exponent}
                        onChange={(event) => form.setData('exponent', toLatinDigits(event.target.value))}
                    >
                        {exponents.map((places) => (
                            <NativeSelectOption key={places} value={String(places)}>
                                {places}
                            </NativeSelectOption>
                        ))}
                    </SelectField>

                    <Names form={form} prefix="new" />

                    <TextField
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
                </CardContent>

                <CardFooter className="justify-end border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                    <ActionButton type="submit" data-test="create-currency" loading={form.processing}>
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

function Names({ form, prefix }: { form: NamesForm; prefix: string }) {
    const t = useTranslator();
    const field = (key: string, label: string, lang: 'ar' | 'en', hint?: string) => (
        <TextField
            key={key}
            id={`${prefix}-${key}`}
            label={label}
            helper={hint}
            error={form.errors[key]}
            required
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
