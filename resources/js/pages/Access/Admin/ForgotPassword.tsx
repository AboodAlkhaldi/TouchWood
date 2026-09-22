import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';

/*
| A4 - forgot password (frontend.md §3.1).
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

                <Field id="email" label={t('access::auth.email')} error={form.errors.email}>
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

                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('access::auth.send_link')}
                </Button>

                <a href="/admin/sign-in" className="text-sm text-brand hover:text-accent">
                    {t('access::auth.back_to_sign_in')}
                </a>
            </form>
        </SignInLayout>
    );
}
