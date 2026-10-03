import { Link, useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { FormError } from '@/components/FormError';
import { Button, Input } from '@/components/geist';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';

/*
| F6, the first half - asking for a new password (frontend.md §3.6), on Geist's field and button
| (1.10).
|
| The answer is the same whether or not the address has an account (access.md §1.8), so this page
| cannot tell the person anything either: it says a link has been sent, and means it the same way
| both times.
*/

export default function ForgotPassword() {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ email: '' });

    return (
        <StorefrontLayout title={t('access::auth.forgot_title')}>
            <ShopCard
                title={t('access::auth.forgot_title')}
                subtitle={t('access::auth.forgot_subtitle')}
                footer={
                    <Link href={link('storefront.sign-in')} className="text-brand hover:underline">
                        {t('access::auth.back_to_sign_in')}
                    </Link>
                }
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.password.forgot'));
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

                    <Button typeName="submit" loading={form.processing} className="w-full">
                        {t('access::auth.send_link')}
                    </Button>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
