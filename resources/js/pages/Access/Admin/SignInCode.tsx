import { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { CodeInput } from '@/components/CodeInput';
import { FormError } from '@/components/FormError';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import type { SignInCodePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A3 - the SMS code (frontend.md §3.1), and A7, which is the same screen after an invitation, on
| shadcn's parts (§1.11): the code is shadcn's InputOTP (components/CodeInput), "Trust this browser"
| its field-checkbox, in login-02's FieldGroup.
|
| How many boxes is a setting, 4 to 8 (Access amendment 22), sent with the page, so nothing here
| assumes six. The number the code went to is named but never shown: Access masks it to its last
| three digits before it reaches this page, so the whole number is not in a page anyone holding the
| browser can read (stage 2b, P4).
|
| "Trust this browser" and the number of days are both the server's to decide; this only shows them.
| Sending another code is out of reach until the wait is over, and says why (Geist: a disabled
| button explains itself); the countdown in its label is the server's number.
*/

// The shape is generated from the PHP that produces it (frontend.md 1.6): renaming a field there
// breaks this build rather than the live page.
type Props = SignInCodePage;

export default function SignInCode({ maskedPhone, length, trustDays, resendIn, action, resendAction }: Props) {
    const t = useTranslator();
    const form = useForm({ code: '', trust_browser: false });
    const [waiting, setWaiting] = useState(resendIn);

    useEffect(() => {
        if (waiting <= 0) {
            return;
        }

        const tick = window.setInterval(() => setWaiting((left) => Math.max(0, left - 1)), 1000);

        return () => window.clearInterval(tick);
    }, [waiting > 0]);

    return (
        <SignInLayout title={t('access::auth.code_title')} subtitle={maskedPhone === null ? undefined : t('access::auth.code_sent_to', { phone: maskedPhone })}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(action);
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <CodeInput
                        id="code"
                        label={t('access::auth.code_label')}
                        length={length}
                        value={form.data.code}
                        onChange={(code) => form.setData('code', code)}
                        error={form.errors.code}
                        autoFocus
                    />

                    <Field orientation="horizontal">
                        <Checkbox
                            id="trust_browser"
                            checked={form.data.trust_browser}
                            onCheckedChange={(checked) => form.setData('trust_browser', checked === true)}
                            className="border-ink-subtle"
                        />
                        <FieldLabel htmlFor="trust_browser" className="text-label-14 font-normal text-ink">
                            {t('access::auth.trust_browser', { days: trustDays })}
                        </FieldLabel>
                    </Field>

                    <Field>
                        <ActionButton type="submit" loading={form.processing} className="w-full">
                            {t('access::auth.confirm')}
                        </ActionButton>

                        <ActionButton
                            type="button"
                            variant="ghost"
                            disabledReason={waiting > 0 ? t('access::auth.resend_wait_reason') : undefined}
                            onClick={() => {
                                router.post(resendAction, {}, { preserveScroll: true });
                                setWaiting(resendIn);
                            }}
                            className="tabular-nums"
                        >
                            {waiting > 0 ? t('access::auth.resend_in', { seconds: waiting }) : t('access::auth.resend')}
                        </ActionButton>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
