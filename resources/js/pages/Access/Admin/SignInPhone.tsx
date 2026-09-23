import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';

/*
| A2 - a new number (frontend.md §3.1).
|
| Only a Super Admin whose phone was reset from the console reaches this, and only after the right
| password. Anyone else would be past the code step with a password alone, which is the whole reason
| the step exists. Opened out of turn, the server sends the person back to A1.
*/

export default function SignInPhone() {
    const t = useTranslator();
    const form = useForm({ phone: '' });

    return (
        <SignInLayout title={t('access::auth.phone_title')} subtitle={t('access::auth.phone_subtitle')}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/sign-in/phone');
                }}
                className="grid gap-5"
            >
                <FormError />

                <Field
                    id="phone"
                    label={t('access::auth.phone')}
                    hint={t('access::auth.phone_hint')}
                    error={form.errors.phone}
                >
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        autoComplete="tel"
                        required
                        autoFocus
                        // A number reads left to right in both languages, and its digits stay 0-9
                        // even on an Arabic page (frontend.md §1.8).
                        dir="ltr"
                        className="tw-figure"
                        value={form.data.phone}
                        onChange={(event) => form.setData('phone', event.target.value)}
                    />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('access::auth.send_code')}
                </Button>
            </form>
        </SignInLayout>
    );
}
