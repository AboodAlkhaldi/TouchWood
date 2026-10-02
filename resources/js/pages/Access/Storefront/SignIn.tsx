import { Link, useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button, Checkbox, Input } from '@/components/geist';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CustomerSignInPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F5 - signing in to the shop (frontend.md §3.6), on Geist's fields and button (1.10).
|
| Email, password, "keep me signed in" - which customers have and staff do not (access.md §1.8) -
| and the way to a new password. One step: the code the panel asks for is a staff rule, and a
| shopper is not asked for a phone to get in.
|
| "Keep me signed in" is a checkbox rather than Geist's Toggle: a Toggle takes effect the moment it
| flips, and this one only means something once the form is sent.
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
                        <Link href={link('storefront.register')} className="text-brand hover:underline">
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

                    <Input
                        id="email"
                        name="email"
                        type="email"
                        label={t('access::auth.customer_email')}
                        error={form.errors.email}
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
                        error={form.errors.password}
                        autoComplete="current-password"
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />

                    <Checkbox
                        id="remember"
                        data-test="remember"
                        checked={form.data.remember}
                        onChange={(checked) => form.setData('remember', checked)}
                    >
                        {t('access::auth.remember_me', { days: rememberDays })}
                    </Checkbox>

                    <Button typeName="submit" loading={form.processing} className="w-full">
                        {t('access::auth.sign_in')}
                    </Button>

                    <Link
                        href={link('storefront.password.forgot')}
                        className="w-fit text-label-14 text-brand hover:underline"
                    >
                        {t('access::auth.forgot_password')}
                    </Link>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
