import { Link, useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/PasswordInput';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { CustomerRegisterPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F3 - registering (frontend.md §3.6).
|
| One page, with the choice at the top and the fields under it (owner, 2026-09-24): a shopper sends
| it once, and can still change their mind before they do. **The choice can never be changed after
| that** (access.md §1.1), which is why it is said beside the choice rather than in a footnote - a
| company's application belongs to B2B, and B2B reads this field.
|
| The language sent with the form is the one the shop is being read in. It is the customer's
| *communication* language, not a display setting: it decides which language their email arrives
| in, and somebody registering on an Arabic page has already said which one that is.
|
| Until B2B exists a company account can sign in but cannot order, which is what its card says in
| plain words rather than as a warning after the fact.
*/

type Props = CustomerRegisterPage;

export default function Register({ minimumLength }: Props) {
    const t = useTranslator();
    const link = useLink();
    const { locale } = usePage<SharedProps>().props;

    const form = useForm({
        account_type: 'INDIVIDUAL',
        email: '',
        password: '',
        first_name: '',
        last_name: '',
        locale,
        terms: false,
    });

    return (
        <StorefrontLayout title={t('access::auth.register_title')}>
            <ShopCard
                title={t('access::auth.register_title')}
                subtitle={t('access::auth.register_subtitle')}
                footer={
                    <>
                        {t('access::auth.have_account')}{' '}
                        <Link href={link('storefront.sign-in')} className="text-brand hover:text-accent">
                            {t('access::auth.sign_in')}
                        </Link>
                    </>
                }
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.register'));
                    }}
                    className="grid gap-5"
                >
                    <FormError />

                    <fieldset className="grid gap-2">
                        <legend className="pb-2 text-sm font-medium text-ink">
                            {t('access::auth.account_type')}
                        </legend>

                        <div className="grid gap-2 sm:grid-cols-2">
                            <AccountType
                                value="INDIVIDUAL"
                                chosen={form.data.account_type}
                                onChoose={(value) => form.setData('account_type', value)}
                                label={t('access::auth.account_type_individual')}
                                hint={t('access::auth.account_type_individual_hint')}
                            />
                            <AccountType
                                value="COMPANY"
                                chosen={form.data.account_type}
                                onChoose={(value) => form.setData('account_type', value)}
                                label={t('access::auth.account_type_company')}
                                hint={t('access::auth.account_type_company_hint')}
                            />
                        </div>

                        <p className="text-xs text-ink-muted">{t('access::auth.account_type_permanent')}</p>

                        {form.errors.account_type ? (
                            <p role="alert" className="text-xs text-bad">
                                {form.errors.account_type}
                            </p>
                        ) : null}
                    </fieldset>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            id="first_name"
                            label={t('access::auth.first_name')}
                            error={form.errors.first_name}
                        >
                            <Input
                                id="first_name"
                                name="first_name"
                                autoComplete="given-name"
                                required
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                            />
                        </Field>

                        <Field
                            id="last_name"
                            label={t('access::auth.last_name')}
                            error={form.errors.last_name}
                        >
                            <Input
                                id="last_name"
                                name="last_name"
                                autoComplete="family-name"
                                required
                                value={form.data.last_name}
                                onChange={(event) => form.setData('last_name', event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field id="email" label={t('access::auth.customer_email')} error={form.errors.email}>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autoComplete="username"
                            required
                            dir="ltr"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="password"
                        label={t('access::auth.password')}
                        hint={t('access::auth.password_rule', { count: minimumLength })}
                        error={form.errors.password}
                    >
                        <PasswordInput
                            id="password"
                            name="password"
                            autoComplete="new-password"
                            required
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />
                    </Field>

                    <div className="grid gap-1.5">
                        <label className="flex items-start gap-2 text-sm text-ink">
                            <Checkbox
                                data-test="terms"
                                className="mt-0.5"
                                checked={form.data.terms}
                                onCheckedChange={(checked) => form.setData('terms', checked === true)}
                            />
                            {t('access::auth.terms_accept')}
                        </label>

                        {form.errors.terms ? (
                            <p role="alert" className="text-xs text-bad">
                                {form.errors.terms}
                            </p>
                        ) : null}
                    </div>

                    <Button type="submit" disabled={form.processing} className="w-full">
                        {t('access::auth.create_account')}
                    </Button>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}

/**
 * One of the two kinds of account, as a card rather than a bare radio dot: the difference between
 * them is what the hint says, and a hint nobody reads is how this choice comes to be regretted.
 *
 * It is a real radio underneath, so the arrow keys move between the two and a screen reader says
 * which of how many this is - neither of which a div with a click handler does.
 */
function AccountType({
    value,
    chosen,
    onChoose,
    label,
    hint,
}: {
    value: string;
    chosen: string;
    onChoose: (value: string) => void;
    label: string;
    hint: string;
}) {
    const isChosen = chosen === value;

    return (
        <label
            data-test={`account-type-${value.toLowerCase()}`}
            className={`grid cursor-pointer gap-1 rounded-lg border p-3 transition-colors ${
                isChosen ? 'border-brand bg-brand-soft/50' : 'border-line hover:border-brand'
            }`}
        >
            <span className="flex items-center gap-2">
                <input
                    type="radio"
                    name="account_type"
                    value={value}
                    checked={isChosen}
                    onChange={() => onChoose(value)}
                    className="accent-brand"
                />
                <span className="text-sm font-medium text-ink">{label}</span>
            </span>

            <span className="text-xs text-ink-muted">{hint}</span>
        </label>
    );
}
