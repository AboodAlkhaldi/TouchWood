import { Link, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Field, FieldDescription, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';

/*
| A4 - forgot password (frontend.md §3.1), on shadcn's login-02 form (§1.11): the way back to sign
| in is the block's centred FieldDescription under the button.
|
| The answer is the same whether or not the account exists, so this page cannot tell the person
| anything either: it says a link has been sent, and means it the same way both times.
*/

export default function ForgotPassword() {
    const t = useTranslator();
    const form = useForm({ email: '' });

    return (
        <SignInLayout title={t('access::auth.forgot_title')} subtitle={t('access::auth.forgot_subtitle')}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/password/forgot');
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <TextField
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

                    <Field>
                        <ActionButton type="submit" loading={form.processing} className="w-full">
                            {t('access::auth.send_link')}
                        </ActionButton>
                        <FieldDescription className="text-center">
                            <Link href="/admin/sign-in" className="underline underline-offset-4">
                                {t('access::auth.back_to_sign_in')}
                            </Link>
                        </FieldDescription>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
