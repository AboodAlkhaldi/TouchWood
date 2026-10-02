import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { FormError } from '@/components/FormError';
import { Button, Input } from '@/components/geist';
import { useTranslator } from '@/lib/t';

/*
| A2 - a new number (frontend.md §3.1), in Geist (1.10).
|
| Only a Super Admin whose phone was reset from the console reaches this, and only after the right
| password. Anyone else would be past the code step with a password alone, which is the whole reason
| the step exists. Opened out of turn, the server sends the person back to A1.
*/

/**
 * The figure face (§1.8) on the box inside Geist's Input. The Input takes its class on the field as
 * a whole - label and helper included - so `tw-figure` there would set the label in the mono face
 * too. This is the same rule aimed at the box alone: mono with even digits, and the Arabic face on
 * an Arabic page, exactly as `tw-figure` does.
 */
const FIGURES = '[&_input]:font-mono [&_input]:tabular-nums [[lang=ar]_&_input]:font-sans';

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

                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    label={t('access::auth.phone')}
                    helper={t('access::auth.phone_hint')}
                    error={form.errors.phone}
                    autoComplete="tel"
                    required
                    autoFocus
                    // A number reads left to right in both languages, and its digits stay 0-9
                    // even on an Arabic page (frontend.md §1.8).
                    dir="ltr"
                    className={FIGURES}
                    value={form.data.phone}
                    onChange={(event) => form.setData('phone', event.target.value)}
                />

                <Button typeName="submit" loading={form.processing} className="w-full">
                    {t('access::auth.send_code')}
                </Button>
            </form>
        </SignInLayout>
    );
}
