import { Link, useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PasswordInput } from '@/components/PasswordInput';
import { ShopCard } from '@/components/ShopCard';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { CustomerRegisterPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F3 - registering (frontend.md §3.6), on shadcn's `signup-01` form (§1.11), with the kind of account
| as shadcn's field-choice-card.
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
        // Nothing chosen: the kind of account can never be changed, so it is picked on purpose,
        // never taken by default (Geist's Radio rule; owner, 2026-10-04).
        account_type: '',
        email: '',
        password: '',
        first_name: '',
        last_name: '',
        locale,
        terms: false,
    });

    return (
        <StorefrontLayout title={t('access::auth.register_title')}>
            <ShopCard title={t('access::auth.register_title')} subtitle={t('access::auth.register_subtitle')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();

                        // Said here, in the page's own words, before anything is sent: the server's
                        // own refusal of a missing field is Laravel's English.
                        if (form.data.account_type === '') {
                            form.setError('account_type', t('access::auth.account_type_required'));

                            return;
                        }

                        form.post(link('storefront.account.register'));
                    }}
                >
                    <FieldGroup className="gap-5">
                        <FormError />

                        {/* shadcn's field-choice-card: each tile is the label of a real radio, so
                            the whole tile is the target, the arrow keys move between the two, and
                            a screen reader says which of how many this is. */}
                        <FieldSet aria-describedby={form.errors.account_type ? 'account_type-error' : 'account_type-permanent'}>
                            <FieldLegend variant="label" className="text-label-14 text-ink">
                                {t('access::auth.account_type')}
                            </FieldLegend>

                            <RadioGroup
                                name="account_type"
                                value={form.data.account_type}
                                onValueChange={(value) => {
                                    form.setData('account_type', value);
                                    form.clearErrors('account_type');
                                }}
                                className="gap-2"
                            >
                                <KindTile
                                    value="INDIVIDUAL"
                                    title={t('access::auth.account_type_individual')}
                                    hint={t('access::auth.account_type_individual_hint')}
                                    invalid={form.errors.account_type !== undefined}
                                />
                                <KindTile
                                    value="COMPANY"
                                    title={t('access::auth.account_type_company')}
                                    hint={t('access::auth.account_type_company_hint')}
                                    invalid={form.errors.account_type !== undefined}
                                />
                            </RadioGroup>

                            <div id="account_type-permanent">
                                <Note variant="warning" size="small">
                                    {t('access::auth.account_type_permanent')}
                                </Note>
                            </div>

                            {form.errors.account_type ? <FieldError id="account_type-error">{form.errors.account_type}</FieldError> : null}
                        </FieldSet>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <TextField
                                id="first_name"
                                name="first_name"
                                label={t('access::auth.first_name')}
                                error={form.errors.first_name}
                                autoComplete="given-name"
                                required
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                            />

                            <TextField
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

                        <TextField
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
                        <Field orientation="horizontal" data-invalid={form.errors.terms ? true : undefined}>
                            <Checkbox
                                id="terms"
                                data-test="terms"
                                checked={form.data.terms}
                                onCheckedChange={(checked) => form.setData('terms', checked === true)}
                                aria-invalid={form.errors.terms ? true : undefined}
                                aria-describedby={form.errors.terms ? 'terms-error' : undefined}
                                className="border-ink-subtle"
                            />
                            <FieldLabel htmlFor="terms" className="text-label-14 font-normal text-ink">
                                {t('access::auth.terms_accept')}
                            </FieldLabel>
                        </Field>
                        {form.errors.terms ? <FieldError id="terms-error">{form.errors.terms}</FieldError> : null}

                        <Field>
                            <ActionButton type="submit" loading={form.processing} className="w-full">
                                {t('access::auth.create_account')}
                            </ActionButton>
                            <FieldDescription className="text-center">
                                {t('access::auth.have_account')} <Link href={link('storefront.sign-in')}>{t('access::auth.sign_in')}</Link>
                            </FieldDescription>
                        </Field>
                    </FieldGroup>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}

/**
 * One kind of account, as shadcn's field-choice-card writes a tile: the whole tile is the label of
 * a real radio, so it is all one target, and the title and the hint are what a screen reader hears.
 */
function KindTile({ value, title, hint, invalid }: { value: string; title: string; hint: string; invalid: boolean }) {
    const id = `account-type-${value.toLowerCase()}`;

    return (
        // The whole tile shows the keyboard's place, not only its dot (Geist's Choicebox).
        <FieldLabel
            htmlFor={id}
            className="has-[[data-state=checked]]:border-brand has-[[data-state=checked]]:bg-brand-soft/40 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
        >
            <Field orientation="horizontal">
                <FieldContent>
                    <FieldTitle id={`${id}-title`} className="text-label-14 text-ink">
                        {title}
                    </FieldTitle>
                    <FieldDescription id={`${id}-hint`} className="text-copy-13 text-ink-muted">
                        {hint}
                    </FieldDescription>
                </FieldContent>
                <RadioGroupItem
                    value={value}
                    id={id}
                    aria-labelledby={`${id}-title`}
                    aria-describedby={`${id}-hint`}
                    aria-invalid={invalid || undefined}
                    className="border-ink-subtle"
                    data-test={id}
                />
            </Field>
        </FieldLabel>
    );
}
