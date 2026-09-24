import { Link, useForm } from '@inertiajs/react';
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
import type { CustomerSignInPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F5 - signing in to the shop (frontend.md §3.6).
|
| Email, password, "keep me signed in" - which customers have and staff do not (access.md §1.8) -
| and the way to a new password. One step: the code the panel asks for is a staff rule, and a
| shopper is not asked for a phone to get in.
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

    return (
        <StorefrontLayout title={t('access::auth.sign_in')}>
            <ShopCard
                title={t('access::auth.sign_in')}
                subtitle={t('access::auth.shop_sign_in_subtitle')}
                footer={
                    <>
                        {t('access::auth.no_account')}{' '}
                        <Link href={link('storefront.register')} className="text-brand hover:text-accent">
                            {t('access::auth.create_account')}
                        </Link>
                    </>
                }
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.sign-in'));
                    }}
                    className="grid gap-5"
                >
                    <FormError />

                    <Field id="email" label={t('access::auth.customer_email')} error={form.errors.email}>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autoComplete="username"
                            required
                            autoFocus
                            dir="ltr"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>

                    <Field id="password" label={t('access::auth.password')} error={form.errors.password}>
                        <PasswordInput
                            id="password"
                            name="password"
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />
                    </Field>

                    <label className="flex items-center gap-2 text-sm text-ink">
                        <Checkbox
                            data-test="remember"
                            checked={form.data.remember}
                            onCheckedChange={(checked) => form.setData('remember', checked === true)}
                        />
                        {t('access::auth.remember_me', { days: rememberDays })}
                    </label>

                    <Button type="submit" disabled={form.processing} className="w-full">
                        {t('access::auth.sign_in')}
                    </Button>

                    <Link
                        href={link('storefront.password.forgot')}
                        className="text-sm text-brand hover:text-accent"
                    >
                        {t('access::auth.forgot_password')}
                    </Link>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
