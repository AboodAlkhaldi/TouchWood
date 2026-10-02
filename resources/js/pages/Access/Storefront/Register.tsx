import { Link, useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button, Checkbox, FieldMessage, Input, Note } from '@/components/geist';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { CustomerRegisterPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F3 - registering (frontend.md §3.6), on Geist's fields, checkbox and button (1.10).
|
| One page, with the choice at the top and the fields under it (owner, 2026-09-24): a shopper sends
| it once, and can still change their mind before they do. **The choice can never be changed after
| that** (access.md §1.1), which is why it is said beside the choice - as Geist's warning Note, the
| component Geist gives for a consequence placed next to the picker it concerns - rather than in a
| footnote. A company's application belongs to B2B, and B2B reads this field.
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
                        <Link href={link('storefront.sign-in')} className="text-brand hover:underline">
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

                    <fieldset className="grid gap-2" aria-describedby={form.errors.account_type ? 'account_type-error' : undefined}>
                        <legend className="pb-2 text-label-14 font-medium text-ink">
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

                        <Note variant="warning" size="small">
                            {t('access::auth.account_type_permanent')}
                        </Note>

                        <FieldMessage id="account_type" error={form.errors.account_type} />
                    </fieldset>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Input
                            id="first_name"
                            name="first_name"
                            label={t('access::auth.first_name')}
                            error={form.errors.first_name}
                            autoComplete="given-name"
                            required
                            value={form.data.first_name}
                            onChange={(event) => form.setData('first_name', event.target.value)}
                        />

                        <Input
                            id="last_name"
                            name="last_name"
                            label={t('access::auth.last_name')}
                            error={form.errors.last_name}
                            autoComplete="family-name"
                            required
                            value={form.data.last_name}
                            onChange={(event) => form.setData('last_name', event.target.value)}
                        />
                    </div>

                    <Input
                        id="email"
                        name="email"
                        type="email"
                        label={t('access::auth.customer_email')}
                        error={form.errors.email}
                        autoComplete="username"
                        required
                        dir="ltr"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />

                    <PasswordInput
                        id="password"
                        name="password"
                        label={t('access::auth.password')}
                        helper={t('access::auth.password_rule', { count: minimumLength })}
                        error={form.errors.password}
                        autoComplete="new-password"
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />

                    {/* An acknowledgment, so a checkbox with a whole sentence beside it (Geist's
                        Checkbox rules); it is checked when the form is sent, not as it is ticked. */}
                    <div className="grid gap-1.5">
                        <Checkbox
                            id="terms"
                            data-test="terms"
                            checked={form.data.terms}
                            onChange={(checked) => form.setData('terms', checked)}
                        >
                            {t('access::auth.terms_accept')}
                        </Checkbox>

                        <FieldMessage id="terms" error={form.errors.terms} />
                    </div>

                    <Button typeName="submit" loading={form.processing} className="w-full">
                        {t('access::auth.create_account')}
                    </Button>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}

/**
 * One of the two kinds of account, as a tile rather than a bare radio dot: the difference between
 * them is what the hint says, and a hint nobody reads is how this choice comes to be regretted.
 *
 * Geist's Choicebox, which the Geist components do not have yet, built from Geist's parts (frontend.md
 * 1.10): the whole tile is the target, a title and one sentence, and the chosen tile shows its filled
 * dot as well as its outline, because an outline alone is not enough on a dim screen.
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
            className={[
                'grid cursor-pointer gap-1 rounded-[var(--tw-radius)] p-3 transition-shadow',
                isChosen
                    ? 'bg-brand-soft/50 shadow-[0_0_0_1px_var(--tw-brand)]'
                    : 'bg-surface shadow-[0_0_0_1px_var(--tw-line-strong)] hover:shadow-[0_0_0_1px_var(--tw-ink-subtle)]',
            ].join(' ')}
        >
            <span className="flex items-center gap-2">
                <input
                    type="radio"
                    name="account_type"
                    value={value}
                    checked={isChosen}
                    onChange={() => onChoose(value)}
                    className="size-4 accent-brand"
                />
                <span className="text-label-14 font-medium text-ink">{label}</span>
            </span>

            <span className="text-copy-13 text-ink-muted">{hint}</span>
        </label>
    );
}
