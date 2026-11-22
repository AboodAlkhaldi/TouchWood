import { Link, useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { ShopCard } from '@/components/ShopCard';
import { Field, FieldDescription, FieldGroup } from '@/components/ui/field';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';

/*
| F6, the first half - asking for a new password (frontend.md §3.6), on shadcn's `login-01` form
| (§1.11): the way back to sign in is the block's centred FieldDescription under the button.
|
| The answer is the same whether or not the address has an account (access.md §1.8), so this page
| cannot tell the person anything either: it says a link has been sent, and means it the same way
| both times.
*/

export default function ForgotPassword() {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ email: '' });
    // The box as typed (frontend.md §1.7), with the server's rules: required (EmailRequest), in an
    // address's shape (EmailAddress, whose own check is the stricter one).
    const checks = useChecks([{ id: 'email', label: t('access::auth.customer_email'), value: form.data.email, rules: { required: true, email: true } }]);

    return (
        <StorefrontLayout title={t('access::auth.forgot_title')}>
            <ShopCard title={t('access::auth.forgot_title')} subtitle={t('access::auth.forgot_subtitle')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        checks.submit(() => form.post(link('storefront.account.password.forgot')));
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

                        <Field>
                            <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} className="w-full">
                                {t('access::auth.send_link')}
                            </ActionButton>
                            <FieldDescription className="text-center">
                                <Link href={link('storefront.sign-in')}>{t('access::auth.back_to_sign_in')}</Link>
                            </FieldDescription>
                        </Field>
                    </FieldGroup>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
