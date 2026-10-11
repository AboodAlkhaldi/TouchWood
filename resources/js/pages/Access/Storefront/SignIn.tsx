import { Link, useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { ShopCard } from '@/components/ShopCard';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CustomerSignInPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F5 - signing in to the shop (frontend.md §3.6), on shadcn's `login-01` form as it writes it
| (§1.11): a FieldGroup of Fields, the reset link on the password's label row, and the way to a new
| account as the form's last line, inside the card.
|
| Email, password, "keep me signed in" - which customers have and staff do not (access.md §1.8). One
| step: the code the panel asks for is a staff rule, and a shopper is not asked for a phone to get in.
|
| "Keep me signed in" is a checkbox, not Geist's Toggle: a Toggle is for "a single boolean setting
| where ON takes effect immediately", and this one means something only once the form is sent.
|
| The answer never says whether the address has an account, so this page holds no logic about which
| refusal it was: a blocked account and a pending deletion are Access's to word, and they arrive as
| a form error like any other.
*/

type Props = CustomerSignInPage;

export default function SignIn({ rememberDays }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ email: '', password: '', remember: false });
    // Each box as typed (frontend.md §1.7), with the server's rules: both required (SignInRequest), the
    // email in an address's shape (EmailAddress, whose own check is the stricter one), the password
    // never trimmed (LaravelPasswordPolicy reads it as typed). Its length is not said: signing in
    // checks a password, it does not judge one.
    const checks = useChecks([
        { id: 'email', label: t('access::auth.customer_email'), value: form.data.email, rules: { required: true, email: true } },
        { id: 'password', label: t('access::auth.password'), value: form.data.password, rules: { required: true, keepSpaces: true } },
    ]);

    return (
        <StorefrontLayout title={t('access::auth.sign_in')}>
            <ShopCard title={t('access::auth.sign_in')} subtitle={t('access::auth.shop_sign_in_subtitle')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        checks.submit(() => form.post(link('storefront.account.sign-in')));
                    }}
                >
                    <FieldGroup className="gap-5">
                        <FormError />

                        <TextField
                            id="email"
                            name="email"
                            type="email"
                            label={t('access::auth.customer_email')}
                            check={checks.box('email', form.errors.email)}
                            autoComplete="username"
                            required
                            autoFocus
                            dir="ltr"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />

                        <PasswordInput
                            id="password"
                            name="password"
                            label={t('access::auth.password')}
                            labelEnd={
                                <Link
                                    href={link('storefront.password.forgot')}
                                    className="text-copy-13 text-ink-muted underline-offset-4 hover:text-ink hover:underline"
                                >
                                    {t('access::auth.forgot_password')}
                                </Link>
                            }
                            check={checks.box('password', form.errors.password)}
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />

                        <Field orientation="horizontal">
                            <Checkbox
                                id="remember"
                                data-test="remember"
                                checked={form.data.remember}
                                onCheckedChange={(checked) => form.setData('remember', checked === true)}
                                className="border-ink-subtle"
                            />
                            <FieldLabel htmlFor="remember" className="text-label-14 font-normal text-ink">
                                {t('access::auth.remember_me', { days: rememberDays })}
                            </FieldLabel>
                        </Field>

                        <Field>
                            <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} className="w-full">
                                {t('access::auth.sign_in')}
                            </ActionButton>
                            <FieldDescription className="text-center">
                                {t('access::auth.no_account')} <Link href={link('storefront.register')}>{t('access::auth.create_account')}</Link>
                            </FieldDescription>
                        </Field>
                    </FieldGroup>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
