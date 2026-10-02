import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { FormError } from '@/components/FormError';
import { Button, Input } from '@/components/geist';
import { useTranslator } from '@/lib/t';

/*
| A4 - forgot password (frontend.md §3.1), in Geist (1.10).
|
| The answer is the same whether or not the account exists, so this page cannot tell the person
| anything either: it says a link has been sent, and means it the same way both times.
*/

export default function ForgotPassword() {
    const t = useTranslator();
    const form = useForm({ email: '' });

    return (
        <SignInLayout
            title={t('access::auth.forgot_title')}
            subtitle={t('access::auth.forgot_subtitle')}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/password/forgot');
                }}
                className="grid gap-5"
            >
                <FormError />

                <Input
                    id="email"
                    name="email"
                    type="email"
                    label={t('access::auth.email')}
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

                <a href="/admin/sign-in" className="w-fit text-copy-14 text-brand hover:underline">
                    {t('access::auth.back_to_sign_in')}
                </a>
            </form>
        </SignInLayout>
    );
}
