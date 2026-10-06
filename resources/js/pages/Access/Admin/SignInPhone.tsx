import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';

/*
| A2 - a new number (frontend.md §3.1), on shadcn's login-02 form (§1.11).
|
| Only a Super Admin whose phone was reset from the console reaches this, and only after the right
| password. Anyone else would be past the code step with a password alone, which is the whole reason
| the step exists. Opened out of turn, the server sends the person back to A1.
|
| A plain shadcn Input with a helper line: neither Geist nor shadcn has a phone field, and nothing is
| built for one (owner, §1.11's table). The number reads left to right in both languages, its digits
| in the figure face on the box alone (§1.8).
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
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <TextField
                        id="phone"
                        name="phone"
                        type="tel"
                        label={t('access::auth.phone')}
                        helper={t('access::auth.phone_hint')}
                        error={form.errors.phone}
                        autoComplete="tel"
                        required
                        autoFocus
                        dir="ltr"
                        inputClassName="tw-figure"
                        value={form.data.phone}
                        onChange={(event) => form.setData('phone', event.target.value)}
                    />

                    <Field>
                        <ActionButton type="submit" loading={form.processing} className="w-full">
                            {t('access::auth.send_code')}
                        </ActionButton>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
